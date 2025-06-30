<?php

namespace app\services;

use app\helper\LogHelper;
use app\model\SampleVector;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use app\model\UserFeedRecommendation;
use app\services\concerns\ProvidesUserData;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;

/**
 * Servicio para manejar la "Reacción Inmediata" del feed ante eventos de alto valor.
 */
class QuickUpdateService
{
    use ProvidesUserData;

    private ScoreCalculationService $scoreService;
    private const SIMILAR_SAMPLES_TO_INJECT = 10;
    private const FEED_SIZE = 200; // Mantener consistente con RecommendationService

    public function __construct()
    {
        $this->scoreService = new ScoreCalculationService();
    }

    /**
     * Maneja la actualización en tiempo real del feed de un usuario tras un 'like'.
     *
     * @param int $userId
     * @param int $sampleId
     * @return void
     */
    public function handleLike(int $userId, int $sampleId): void
    {
        LogHelper::info('quick-update', 'Iniciando actualización rápida para like.', ['user_id' => $userId, 'sample_id' => $sampleId]);

        // 1. Registrar la interacción (aún será procesada por el batch principal).
        UserInteraction::create([
            'user_id' => $userId,
            'sample_id' => $sampleId,
            'interaction_type' => 'like',
            'weight' => 1.0,
        ]);

        // 2. Obtener datos necesarios.
        $userProfile = UserTasteProfile::find($userId);
        $likedSample = SampleVector::find($sampleId);

        if (!$userProfile || !$likedSample) {
            LogHelper::warning('quick-update', 'No se pudo encontrar el perfil de usuario o el sample para la actualización rápida.', ['user_id' => $userId, 'sample_id' => $sampleId]);
            return;
        }

        // 3. Encontrar samples candidatos similares al que se le dio 'like'.
        $candidates = $this->findSimilarSamples($likedSample);
        if ($candidates->isEmpty()) {
            LogHelper::info('quick-update', 'No se encontraron candidatos similares para inyectar.', ['sample_id' => $sampleId]);
            return;
        }

        // 4. Calcular el score para los nuevos candidatos.
        $definitiveInteractions = $this->getUserDefinitiveInteractions($userId);
        $followedCreators = $this->getFollowedCreatorsForUsers([$userId])->get($userId, collect())->flip();
        
        $newRecommendations = [];
        foreach ($candidates as $candidate) {
            if ($candidate->sample_id == $sampleId || isset($definitiveInteractions[$candidate->sample_id])) {
                continue;
            }

            $score = $this->scoreService->calculateFinalScore($userProfile->taste_vector, $candidate, $definitiveInteractions, $followedCreators->has($candidate->creator_id));
            
            if ($score > 0) { // Umbral simple para considerar una recomendación.
                 $newRecommendations[] = [
                    'user_id' => $userId,
                    'sample_id' => $candidate->sample_id,
                    'score' => $score,
                    'generated_at' => now()
                ];
            }
        }

        if (empty($newRecommendations)) {
            LogHelper::info('quick-update', 'Ningún candidato similar generó un score positivo.', ['user_id' => $userId]);
            return;
        }

        // 5. Inyectar las nuevas recomendaciones en el feed del usuario.
        $this->injectIntoFeed($userId, $newRecommendations);
        LogHelper::info('quick-update', 'Actualización rápida completada.', ['user_id' => $userId, 'injected_count' => count($newRecommendations)]);
    }

    /**
     * Busca samples con un vector similar al proporcionado, usando la misma lógica
     * de pre-filtrado por GIN index que el proceso batch.
     *
     * @param SampleVector $sample
     * @return Collection
     */
    private function findSimilarSamples(SampleVector $sample): Collection
    {
        $hotIndices = array_keys(array_filter($sample->vector, fn($val) => $val > 0.9));

        if (empty($hotIndices)) {
            return collect();
        }

        $query = SampleVector::query()->where('sample_id', '!=', $sample->sample_id);

        $query->where(function ($q) use ($hotIndices) {
            foreach ($hotIndices as $index) {
                $q->orWhereRaw("vector->>".(int)$index." = '1'");
            }
        });
        
        return $query->inRandomOrder()->limit(self::SIMILAR_SAMPLES_TO_INJECT * 2)->get();
    }
    
    /**
     * Inyecta nuevas recomendaciones al inicio del feed, manteniendo el tamaño total.
     *
     * @param int $userId
     * @param array $newRecommendations
     * @return void
     */
    private function injectIntoFeed(int $userId, array $newRecommendations): void
    {
        DB::transaction(function () use ($userId, $newRecommendations) {
            $newSampleIds = array_column($newRecommendations, 'sample_id');
            $currentFeed = UserFeedRecommendation::where('user_id', $userId)
                ->whereNotIn('sample_id', $newSampleIds)
                ->orderBy('score', 'desc')
                ->get()
                ->toArray();
                
            $combinedFeed = array_merge($newRecommendations, $currentFeed);
            usort($combinedFeed, fn($a, $b) => $b['score'] <=> $a['score']);
            $finalFeed = array_slice($combinedFeed, 0, self::FEED_SIZE);

            UserFeedRecommendation::where('user_id', $userId)->delete();
            UserFeedRecommendation::insert($finalFeed);
        });
    }
}