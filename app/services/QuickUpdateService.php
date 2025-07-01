<?php

namespace app\services;

use app\model\SampleVector;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use app\model\UserFeedRecommendation;
use app\model\UserFollow;
use app\services\concerns\ProvidesUserData;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;

class QuickUpdateService
{
    use ProvidesUserData;

    private ScoreCalculationService $scoreService;
    private const SIMILAR_SAMPLES_TO_INJECT = 10;
    private const FOLLOWED_SAMPLES_TO_INJECT = 15;
    private const FEED_SIZE = 200;

    public function __construct()
    {
        $this->scoreService = new ScoreCalculationService();
    }

    public function handleLike(int $userId, int $sampleId): void
    {
        $this->runQuickUpdateForPositiveSignal($userId, $sampleId, 'like');
    }

    public function handleComment(int $userId, int $sampleId): void
    {
        $this->runQuickUpdateForPositiveSignal($userId, $sampleId, 'comment');
    }

    private function runQuickUpdateForPositiveSignal(int $userId, int $sampleId, string $interactionType): void
    {
        echo "  [DEBUG] QuickUpdate: Iniciando para {$interactionType} de User {$userId} en Sample {$sampleId}\n";
        UserInteraction::create([
            'user_id' => $userId, 'sample_id' => $sampleId,
            'interaction_type' => $interactionType, 'weight' => 1.0,
        ]);

        $userProfile = UserTasteProfile::find($userId);
        $interactedSample = SampleVector::find($sampleId);

        if (!$userProfile || !$interactedSample) {
            echo "  [DEBUG] QuickUpdate: No se encontró perfil de usuario o sample. Abortando.\n";
            return;
        }

        // Aseguramos que el vector de gustos del usuario sea un array. Puede venir como JSON string desde la BD.
        $userTasteVector = is_array($userProfile->taste_vector)
            ? $userProfile->taste_vector
            : json_decode($userProfile->taste_vector, true) ?? [];

        // También nos aseguramos de que el vector del sample de la interacción sea array por coherencia.
        if (!is_array($interactedSample->vector)) {
            $interactedSample->vector = json_decode($interactedSample->vector, true) ?? [];
        }

        $candidates = $this->findSimilarSamples($interactedSample);
        echo "  [DEBUG] QuickUpdate: Se encontraron " . $candidates->count() . " candidatos similares.\n";

        if ($candidates->isEmpty()) {
            $this->injectIntoFeed($userId, [[
                'user_id' => $userId, 'sample_id' => $sampleId,
                'score' => 1.0, 'generated_at' => Carbon::now()->toDateTimeString(),
            ]]);
            return;
        }

        $definitiveInteractions = $this->getUserDefinitiveInteractions($userId);
        $followedCreators = $this->getFollowedCreatorsForUsers([$userId])->get($userId, collect())->flip();
        $newRecommendations = [];

        // --- INICIO DE DEPURACIÓN VERBOSA ---
        echo "  [DEBUG] Iniciando bucle foreach sobre los candidatos...\n";
        foreach ($candidates as $key => $candidate) {
            echo "  [DEBUG] Procesando candidato con clave de bucle: $key\n";
            
            // 1. VERIFICACIÓN DE TIPO
            if (!is_object($candidate) || !$candidate instanceof \app\model\SampleVector) {
                echo "  [ERROR] ¡El candidato no es un objeto SampleVector válido! Tipo: " . gettype($candidate) . "\n";
                continue;
            }
            
            echo "  [DEBUG] ID del candidato: {$candidate->sample_id}\n";
            
            // 2. VERIFICACIÓN DEL VECTOR
            if (!isset($candidate->vector)) {
                echo "  [ERROR] ¡El vector del candidato no está definido (es null)!\n";
                continue;
            }
            if (!is_array($candidate->vector)) {
                echo "  [ERROR] ¡El vector del candidato no es un array! Tipo: " . gettype($candidate->vector) . "\n";
                continue;
            }

            if ($candidate->sample_id == $sampleId) {
                echo "  [DEBUG] Saltando candidato: es el mismo que el sample de la interacción.\n";
                continue;
            }

            echo "  [DEBUG] Llamando a calculateFinalScore...\n";
            $score = $this->scoreService->calculateFinalScore(
                $userTasteVector,
                $candidate,
                $definitiveInteractions,
                $followedCreators->has($candidate->creator_id)
            );
            echo "  [DEBUG] Score calculado para candidato {$candidate->sample_id} es {$score}.\n";

            if ($score >= 0) {
                echo "  [DEBUG] Score es positivo. Añadiendo a recomendaciones.\n";
                $newRecommendations[] = ['user_id' => $userId, 'sample_id' => $candidate->sample_id, 'score' => $score, 'generated_at' => Carbon::now()->toDateTimeString()];
            } else {
                echo "  [DEBUG] Score es negativo. Omitiendo.\n";
            }
        }
        echo "  [DEBUG] Bucle foreach completado.\n";
        // --- FIN DE DEPURACIÓN VERBOSA ---

        echo "  [DEBUG] QuickUpdate: Se generaron " . count($newRecommendations) . " nuevas recomendaciones con score positivo.\n";

        if (empty($newRecommendations)) {
             echo "  [DEBUG] No se generaron nuevas recomendaciones, retornando.\n";
            return;
        }

        $this->injectIntoFeed($userId, $newRecommendations);
    }

    public function handleFollow(int $followerId, int $followedUserId): void
    {
        // El código de este método no se ha modificado para mantener el foco en el primer error (like)
        echo "  [DEBUG] QuickUpdate: Iniciando para follow de User {$followerId} a User {$followedUserId}\n";
        UserFollow::updateOrCreate(['user_id' => $followerId, 'followed_user_id' => $followedUserId]);
        $followerProfile = UserTasteProfile::find($followerId);
        if (!$followerProfile) return;
        $followerTasteVector = is_array($followerProfile->taste_vector)
            ? $followerProfile->taste_vector
            : json_decode($followerProfile->taste_vector, true) ?? [];
        $candidates = SampleVector::where('creator_id', $followedUserId)->latest('created_at')->limit(self::FOLLOWED_SAMPLES_TO_INJECT * 2)->get();
        echo "  [DEBUG] QuickUpdate: Se encontraron " . $candidates->count() . " samples del usuario seguido.\n";
        if ($candidates->isEmpty()) return;
        $definitiveInteractions = $this->getUserDefinitiveInteractions($followerId);
        $newRecommendations = [];
        foreach ($candidates as $candidate) {
            // Aseguramos que el vector del candidato sea array
            if (!is_array($candidate->vector)) {
                $candidate->vector = json_decode($candidate->vector, true) ?? [];
            }

            $score = $this->scoreService->calculateFinalScore($followerTasteVector, $candidate, $definitiveInteractions, true);
            if ($score > 0) {
                $newRecommendations[] = ['user_id' => $followerId, 'sample_id' => $candidate->sample_id, 'score' => $score, 'generated_at' => Carbon::now()->toDateTimeString()];
            }
        }
        if (empty($newRecommendations)) {
            $latestSample = $candidates->first();
            if ($latestSample && !in_array($latestSample->sample_id, $definitiveInteractions)) {
                $newRecommendations[] = ['user_id' => $followerId, 'sample_id' => $latestSample->sample_id, 'score' => 0.5, 'generated_at' => Carbon::now()->toDateTimeString()];
            }
        }
        $this->injectIntoFeed($followerId, array_slice($newRecommendations, 0, self::FOLLOWED_SAMPLES_TO_INJECT));
    }

    private function injectIntoFeed(int $userId, array $newRecommendations): void
    {
        echo "  [DEBUG] injectIntoFeed: Entrando para User {$userId} con " . count($newRecommendations) . " recs nuevas.\n";
        if (empty($newRecommendations)) return;
        try {
            DB::transaction(function () use ($userId, $newRecommendations) {
                $newSampleIds = array_column($newRecommendations, 'sample_id');
                $currentFeed = UserFeedRecommendation::where('user_id', $userId)->whereNotIn('sample_id', $newSampleIds)->orderBy('score', 'desc')->get()->toArray();
                $combinedFeed = array_merge($newRecommendations, $currentFeed);
                usort($combinedFeed, fn($a, $b) => $b['score'] <=> $a['score']);
                $finalFeed = array_slice($combinedFeed, 0, self::FEED_SIZE);
                echo "  [DEBUG] injectIntoFeed: Feed final combinado con " . count($finalFeed) . " elementos.\n";
                UserFeedRecommendation::where('user_id', $userId)->delete();
                if (!empty($finalFeed)) {
                    $inserted = UserFeedRecommendation::insert($finalFeed);
                    echo "  [DEBUG] injectIntoFeed: Resultado de la operación de BD 'insert' fue: " . ($inserted ? 'true' : 'false') . ".\n";
                }
            });
            $countAfter = UserFeedRecommendation::where('user_id', $userId)->count();
            echo "  [DEBUG] injectIntoFeed: Conteo final en BD (post-transacción) es: {$countAfter}.\n";
        } catch (\Throwable $e) {
            echo "\033[31m";
            echo "  [DEBUG] EXCEPCION CATASTROFICA en injectIntoFeed:\n";
            echo "  [DEBUG] Mensaje: " . $e->getMessage() . "\n";
            echo "  [DEBUG] Archivo: " . $e->getFile() . " Linea: " . $e->getLine() . "\n";
            echo "\033[0m";
        }
    }

    public function handleUnlike(int $userId, int $sampleId): void
    {
        UserInteraction::create(['user_id' => $userId, 'sample_id' => $sampleId, 'interaction_type' => 'dislike', 'weight' => -1.0]);
        UserFeedRecommendation::where('user_id', $userId)->where('sample_id', $sampleId)->delete();
    }

    public function handleUnfollow(int $followerId, int $unfollowedUserId): void
    {
        UserFollow::where('user_id', $followerId)->where('followed_user_id', $unfollowedUserId)->delete();
        $sampleIdsToRemove = SampleVector::where('creator_id', $unfollowedUserId)->pluck('sample_id');
        if ($sampleIdsToRemove->isEmpty()) { return; }
        UserFeedRecommendation::where('user_id', $followerId)->whereIn('sample_id', $sampleIdsToRemove)->delete();
    }

    private function findSimilarSamples(SampleVector $sample): Collection
    {
        $vectorData = is_array($sample->vector) ? $sample->vector : json_decode($sample->vector, true);
        $hotIndices = array_keys(array_filter($vectorData, fn($val) => $val > 0.9));
        if (empty($hotIndices)) { return collect(); }
        $query = SampleVector::query()->where('sample_id', '!=', $sample->sample_id);
        $query->where(function ($q) use ($hotIndices) {
            foreach ($hotIndices as $index) {
                $q->orWhereRaw("CAST (vector->>" . (int)$index . " AS numeric) > 0.9");
            }
        });
        return $query->inRandomOrder()->limit(self::SIMILAR_SAMPLES_TO_INJECT * 2)->get();
    }
}