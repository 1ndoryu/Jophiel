<?php

namespace app\services;

use app\helper\LogHelper;
use app\helper\PerformanceTracker;
use app\model\SampleVector;
use app\model\UserFeedRecommendation;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;

class RecommendationService
{
    private ScoreCalculationService $scoreService;

    // --- Constantes de Configuración ---
    private const INTERACTION_BATCH_SIZE = 1000; // Procesar 1000 interacciones a la vez.
    private const PROFILE_UPDATE_LEARNING_RATE = 0.05; // Tasa de aprendizaje para la actualización del perfil.
    private const CANDIDATE_SAMPLES_LIMIT = 2000; // Límite de samples candidatos a evaluar por usuario.
    private const FEED_SIZE = 200; // Cuántas recomendaciones guardar por usuario.

    public function __construct()
    {
        $this->scoreService = new ScoreCalculationService();
    }

    /**
     * Orquesta la ejecución completa del proceso batch.
     */
    public function runBatchProcess(): void
    {
        LogHelper::info('batch-process', 'Paso 1: Obteniendo nuevas interacciones.');
        $interactions = PerformanceTracker::measure('step_1_get_interactions', fn() => $this->getNewInteractions());

        if ($interactions->isEmpty()) {
            LogHelper::info('batch-process', 'No hay nuevas interacciones que procesar.');
            return;
        }

        $interactionCount = $interactions->count();
        LogHelper::info('batch-process', "Procesando {$interactionCount} interacciones.");

        LogHelper::info('batch-process', 'Paso 2: Actualizando perfiles de gusto de usuarios.');
        $affectedUserIds = PerformanceTracker::measure(
            'step_2_update_profiles',
            fn() => $this->updateUserTasteProfiles($interactions),
            ['interaction_count' => $interactionCount]
        );

        if (empty($affectedUserIds)) {
            LogHelper::info('batch-process', 'Ningún perfil de usuario fue actualizado.');
        } else {
            $userCount = count($affectedUserIds);
            LogHelper::info('batch-process', "Paso 3: Recalculando feeds para {$userCount} usuarios afectados.");
            PerformanceTracker::measure(
                'step_3_recalculate_feeds',
                fn() => $this->recalculateFeedsForUsers($affectedUserIds),
                ['user_count' => $userCount]
            );
        }

        LogHelper::info('batch-process', 'Paso 4: Marcando interacciones como procesadas.');
        PerformanceTracker::measure(
            'step_4_mark_processed',
            fn() => $this->markInteractionsAsProcessed($interactions->pluck('id')->all()),
            ['interaction_count' => $interactionCount]
        );
    }

    private function getNewInteractions(): Collection
    {
        return UserInteraction::whereNull('processed_at')
            ->orderBy('id')
            ->limit(self::INTERACTION_BATCH_SIZE)
            ->get();
    }

    private function updateUserTasteProfiles(Collection $interactions): array
    {
        $interactionsByUser = $interactions->groupBy('user_id');
        $affectedUserIds = $interactionsByUser->keys()->all();

        $userProfiles = UserTasteProfile::findMany($affectedUserIds)->keyBy('user_id');
        $sampleIds = $interactions->pluck('sample_id')->unique()->all();
        $sampleVectors = SampleVector::findMany($sampleIds)->keyBy('sample_id');

        foreach ($interactionsByUser as $userId => $userInteractions) {
            $userProfile = $userProfiles->get($userId);
            if (!$userProfile) {
                LogHelper::warning('batch-process', "No se encontró perfil para el usuario ID: {$userId}. Saltando actualización.");
                continue;
            }

            $tasteVector = json_decode($userProfile->taste_vector, true);

            foreach ($userInteractions as $interaction) {
                $sampleVectorModel = $sampleVectors->get($interaction->sample_id);
                if (!$sampleVectorModel) continue;

                $sampleVector = json_decode($sampleVectorModel->vector, true);
                $weight = (float) $interaction->weight;

                for ($i = 0; $i < count($tasteVector); $i++) {
                    $tasteVector[$i] = (1 - self::PROFILE_UPDATE_LEARNING_RATE) * $tasteVector[$i]
                        + self::PROFILE_UPDATE_LEARNING_RATE * $weight * $sampleVector[$i];
                }
            }

            $userProfile->taste_vector = json_encode($this->normalizeVector($tasteVector));
            $userProfile->save();
        }

        return $affectedUserIds;
    }

    private function recalculateFeedsForUsers(array $userIds): void
    {
        if (empty($userIds)) return;

        $userProfiles = UserTasteProfile::findMany($userIds)->keyBy('user_id');

        $candidateSamples = PerformanceTracker::measure(
            'recalculate_feeds_fetch_candidates',
            fn() => SampleVector::inRandomOrder()->limit(self::CANDIDATE_SAMPLES_LIMIT)->get(),
            ['limit' => self::CANDIDATE_SAMPLES_LIMIT]
        );

        if ($candidateSamples->isEmpty()) {
            LogHelper::warning('batch-process', 'No se encontraron samples candidatos para generar recomendaciones.');
            return;
        }

        foreach ($userIds as $userId) {
            PerformanceTracker::measure(
                'recalculate_feeds_single_user',
                function () use ($candidateSamples, $userId, $userProfiles) {
                    $userProfile = $userProfiles->get($userId);
                    if (!$userProfile) {
                        return;
                    }

                    $recommendations = [];

                    $definitiveInteractions = PerformanceTracker::measure(
                        'recalculate_feeds_fetch_interactions',
                        fn() => UserInteraction::where('user_id', $userId)
                            ->whereIn('interaction_type', ['like', 'dislike'])
                            ->pluck('sample_id')
                            ->all(),
                        ['user_id' => $userId]
                    );

                    foreach ($candidateSamples as $sampleVector) {
                        $isFollowing = false; // TODO: Implementar lógica de seguimiento.

                        $score = $this->scoreService->calculateFinalScore(
                            $userProfile,
                            $sampleVector,
                            $definitiveInteractions,
                            $isFollowing
                        );

                        if ($score > ScoreCalculationService::PENALTY_DEFINITIVE_INTERACTION) {
                            $recommendations[] = [
                                'user_id' => $userId,
                                'sample_id' => $sampleVector->sample_id,
                                'score' => $score
                            ];
                        }
                    }

                    usort($recommendations, fn($a, $b) => $b['score'] <=> $a['score']);
                    $topRecommendations = array_slice($recommendations, 0, self::FEED_SIZE);

                    PerformanceTracker::measure(
                        'recalculate_feeds_save_recommendations',
                        function () use ($userId, $topRecommendations) {
                            DB::transaction(function () use ($userId, $topRecommendations) {
                                UserFeedRecommendation::where('user_id', $userId)->delete();
                                if (!empty($topRecommendations)) {
                                    $now = Carbon::now();
                                    $insertData = array_map(fn($rec) => $rec + ['generated_at' => $now], $topRecommendations);
                                    UserFeedRecommendation::insert($insertData);
                                }
                            });
                        },
                        ['user_id' => $userId, 'recommendation_count' => count($topRecommendations)]
                    );
                    LogHelper::info('batch-process', "Feed recalculado para el usuario ID: {$userId} con " . count($topRecommendations) . " recomendaciones.");
                },
                ['user_id' => $userId]
            );
        }
    }

    private function markInteractionsAsProcessed(array $interactionIds): void
    {
        if (empty($interactionIds)) {
            return;
        }
        UserInteraction::whereIn('id', $interactionIds)->update(['processed_at' => Carbon::now()]);
    }

    private function normalizeVector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));
        if ($magnitude == 0) return $vector;
        return array_map(fn($x) => $x / $magnitude, $vector);
    }
}
