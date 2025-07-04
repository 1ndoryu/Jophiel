<?php

namespace app\services;

use app\helper\LogHelper;
use app\helper\PerformanceTracker;
use app\model\SampleVector;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use app\services\concerns\ProvidesUserData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RecommendationService
{
    use ProvidesUserData;

    private ScoreCalculationService $scoreService;
    private FeedManagerService $feedManager;

    // --- Parámetros configurables (se cargan desde config) ---
    private int $interactionBatchSize;
    private float $profileUpdateLearningRate;
    private float $tasteVectorThreshold;

    private int $candidateSamplesLimit;
    private int $feedSize;

    public function __construct()
    {
        $this->scoreService = new ScoreCalculationService();
        $this->feedManager = new FeedManagerService();

        // Parámetros dinámicos desde config/recommendation.php
        $batchConfig = config('recommendation.batch_processing');

        $this->interactionBatchSize      = (int) ($batchConfig['interaction_batch_size'] ?? 1000);
        $this->profileUpdateLearningRate = (float) ($batchConfig['profile_update_learning_rate'] ?? 0.05);
        $this->tasteVectorThreshold      = (float) ($batchConfig['taste_vector_threshold'] ?? 0.1);

        $this->candidateSamplesLimit = (int) config('recommendation.candidate_search.max_candidates', 1000);
        $this->feedSize             = (int) config('recommendation.recommendations.feed_size', 200);
    }

    public function runBatchProcess(): void
    {
        LogHelper::info('batch-process', 'Paso 1: Obteniendo nuevas interacciones.');
        $interactions = PerformanceTracker::measure('step_1_get_interactions', fn() => $this->getNewInteractions());

        if ($interactions->isEmpty()) {
            LogHelper::info('batch-process', 'No hay nuevas interacciones que procesar. Se recalcularán los feeds para todos los usuarios para reflejar nuevo contenido.');

            // Obtener todos los usuarios que tienen perfil de gustos registrado
            $allUserIds = UserTasteProfile::pluck('user_id')->all();
            if (!empty($allUserIds)) {
                $userCount = count($allUserIds);
                LogHelper::info('batch-process', "Recalculando feeds para {$userCount} usuarios (sin nuevas interacciones).");
                PerformanceTracker::measure('recalculate_feeds_no_interactions', fn() => $this->recalculateFeedsForUsers($allUserIds), ['user_count' => $userCount]);
            } else {
                LogHelper::info('batch-process', 'No existen perfiles de usuario registrados, nada que recalcular.');
            }
            return;
        }

        $interactionCount = $interactions->count();
        LogHelper::info('batch-process', "Procesando {$interactionCount} interacciones.");

        LogHelper::info('batch-process', 'Paso 2: Actualizando perfiles de gusto.');
        $affectedUserIds = PerformanceTracker::measure('step_2_update_profiles', fn() => $this->updateUserTasteProfiles($interactions), ['interaction_count' => $interactionCount]);

        if (empty($affectedUserIds)) {
            LogHelper::info('batch-process', 'Ningún perfil de usuario fue actualizado.');
        } else {
            $userCount = count($affectedUserIds);
            LogHelper::info('batch-process', "Paso 3: Recalculando feeds para {$userCount} usuarios afectados.");
            PerformanceTracker::measure('step_3_recalculate_feeds', fn() => $this->recalculateFeedsForUsers($affectedUserIds), ['user_count' => $userCount]);
        }

        LogHelper::info('batch-process', 'Paso 4: Marcando interacciones como procesadas.');
        PerformanceTracker::measure('step_4_mark_processed', fn() => $this->markInteractionsAsProcessed($interactions->pluck('id')->all()), ['interaction_count' => $interactionCount]);
    }

    /**
     * Recalcula completamente el algoritmo para un usuario específico.
     * Si $forceReset es true, el perfil de gustos se reinicia y se vuelven a procesar TODAS las interacciones.
     */
    public function runFullRecalculationForUser(int $userId, bool $forceReset = false): void
    {
        LogHelper::info('user-recalc', 'Iniciando recalculado completo para usuario.', [
            'user_id'     => $userId,
            'force_reset' => $forceReset,
        ]);

        // --- FORCED RESET --------------------------------------------------
        if ($forceReset) {
            LogHelper::info('user-recalc', 'Forzando reinicio de taste profile y reprocesado de interacciones.', ['user_id' => $userId]);

            // 1. Eliminar perfil de gustos existente (si lo hay)
            \app\model\UserTasteProfile::where('user_id', $userId)->delete();

            // 2. (Re)crear perfil neutro para evitar errores posteriores
            $dimension = (int) config('vectorization.vector_dimension', 35);
            \app\model\UserTasteProfile::create([
                'user_id'      => $userId,
                'taste_vector' => array_fill(0, $dimension, 0.0),
            ]);

            // 3. Marcar TODAS las interacciones como no procesadas
            \app\model\UserInteraction::where('user_id', $userId)->update(['processed_at' => null]);
        }

        // 1. Obtener las interacciones no procesadas de este usuario
        $interactions = UserInteraction::where('user_id', $userId)
            ->whereNull('processed_at')
            ->get();

        // 2. Si existen, actualizar su perfil de gustos y marcarlas como procesadas
        if ($interactions->isNotEmpty()) {
            $this->updateUserTasteProfiles($interactions);
            $this->markInteractionsAsProcessed($interactions->pluck('id')->all());
        }

        // 3. Recalcular y guardar el feed del usuario
        $this->recalculateFeedsForUsers([$userId]);

        LogHelper::info('user-recalc', 'Recalculo completo finalizado para usuario.', ['user_id' => $userId]);
    }

    private function getNewInteractions(): Collection
    {
        return UserInteraction::whereNull('processed_at')->orderBy('id')->limit($this->interactionBatchSize)->get();
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
            if (!$userProfile) continue;

            // NUEVO LOG: Vector de gustos antes de actualizar (primeros 5 valores)
            LogHelper::debug('batch-process', 'Perfil antes de actualización', [
                'user_id' => $userId,
                'vector_head' => array_slice($userProfile->taste_vector, 0, 5)
            ]);

            $tasteVector = $userProfile->taste_vector;

            foreach ($userInteractions as $interaction) {
                $sampleVectorModel = $sampleVectors->get($interaction->sample_id);
                if (!$sampleVectorModel) continue;

                $sampleVector = $sampleVectorModel->vector;
                $weight = (float) $interaction->weight;

                for ($i = 0; $i < count($tasteVector); $i++) {
                    $tasteVector[$i] = (1 - $this->profileUpdateLearningRate) * $tasteVector[$i]
                        + $this->profileUpdateLearningRate * $weight * $sampleVector[$i];
                }
            }

            $userProfile->taste_vector = $this->normalizeVector($tasteVector);
            $userProfile->save();

            // NUEVO LOG: Vector de gustos después de actualizar (primeros 5 valores)
            LogHelper::debug('batch-process', 'Perfil después de actualización', [
                'user_id' => $userId,
                'vector_head' => array_slice($userProfile->taste_vector, 0, 5)
            ]);
        }

        LogHelper::info('batch-process', 'Perfiles de gusto actualizados.', ['updated_user_ids' => $affectedUserIds]);
        return $affectedUserIds;
    }

    private function recalculateFeedsForUsers(array $userIds): void
    {
        if (empty($userIds)) return;

        $userProfiles = UserTasteProfile::findMany($userIds)->keyBy('user_id');
        $allUserFollows = $this->getFollowedCreatorsForUsers($userIds);

        foreach ($userProfiles as $userId => $userProfile) {
            PerformanceTracker::measure('recalculate_feeds_single_user', function () use ($userId, $userProfile, $allUserFollows) {
                LogHelper::info('batch-process', "Iniciando recalculo para usuario.", ['user_id' => $userId]);

                $followedCreators = $allUserFollows->get($userId, collect())->flip();
                $definitiveInteractions = $this->getUserDefinitiveInteractions($userId);

                $candidates = $this->getCandidateSamplesForUser($userProfile);
                LogHelper::info('batch-process', 'Candidatos obtenidos para usuario.', ['user_id' => $userId, 'candidate_count' => $candidates->count()]);

                if ($candidates->isEmpty()) {
                    LogHelper::info('batch-process', 'No se encontraron candidatos para el usuario, saltando.', ['user_id' => $userId]);
                    return;
                }

                $recommendations = $this->scoreCandidates($userProfile, $candidates, $definitiveInteractions, $followedCreators);
                LogHelper::info('batch-process', 'Candidatos puntuados.', ['user_id' => $userId, 'scored_recommendations' => count($recommendations)]);

                usort($recommendations, fn($a, $b) => $b['score'] <=> $a['score']);
                $topRecommendations = array_slice($recommendations, 0, $this->feedSize);

                $top5 = array_map(function ($rec) {
                    return ['sample_id' => $rec['sample_id'], 'score' => round($rec['score'], 4)];
                }, array_slice($topRecommendations, 0, 5));

                LogHelper::info('batch-process', 'Top 5 recomendaciones para usuario.', ['user_id' => $userId, 'top_5' => $top5]);

                $this->feedManager->saveUserFeed($userId, $topRecommendations);

                LogHelper::info('batch-process', "Feed recalculado y guardado para el usuario.", ['user_id' => $userId, 'final_recommendation_count' => count($topRecommendations)]);
            }, ['user_id' => $userId]);
        }
    }

    private function getCandidateSamplesForUser(UserTasteProfile $userProfile): Collection
    {
        $tasteVector = $userProfile->taste_vector;
        $hotIndices = [];
        foreach ($tasteVector as $index => $value) {
            if ($value > $this->tasteVectorThreshold) {
                $hotIndices[] = $index;
            }
        }

        if (empty($hotIndices)) {
            return SampleVector::inRandomOrder()->limit($this->candidateSamplesLimit)->get();
        }

        $query = SampleVector::query();
        $query->where(function ($q) use ($hotIndices) {
            foreach ($hotIndices as $index) {
                // Seleccionamos cualquier sample cuyo valor en la dimensión "caliente" sea mayor que 0.
                // Esto evita excluir samples que tengan valores no binarios (p.e. 0.3) y amplía el conjunto.
                $q->orWhereRaw("CAST(vector->>" . (int)$index . " AS numeric) > 0");
            }
        });

        $candidates = $query->inRandomOrder()->limit($this->candidateSamplesLimit)->get();

        // Fallback: si la búsqueda por índices "calientes" devuelve pocos resultados, rellenamos con aleatorios
        if ($candidates->count() < $this->candidateSamplesLimit) {
            $missing = $this->candidateSamplesLimit - $candidates->count();
            $additional = SampleVector::whereNotIn('sample_id', $candidates->pluck('sample_id'))
                ->inRandomOrder()
                ->limit($missing)
                ->get();
            $candidates = $candidates->concat($additional);
        }

        return $candidates;
    }

    private function scoreCandidates(UserTasteProfile $userProfile, Collection $candidates, array $definitiveInteractions, Collection $followedCreators): array
    {
        $recommendations = [];
        foreach ($candidates as $sample) {
            $isFollowing = $followedCreators->has($sample->creator_id);

            $score = $this->scoreService->calculateFinalScore(
                $userProfile->taste_vector,
                $sample,
                $definitiveInteractions,
                $isFollowing
            );

            if ($score > ScoreCalculationService::PENALTY_DEFINITIVE_INTERACTION) {
                $recommendations[] = [
                    'sample_id' => $sample->sample_id,
                    'score' => $score
                ];
            }
        }
        return $recommendations;
    }

    private function markInteractionsAsProcessed(array $interactionIds): void
    {
        if (empty($interactionIds)) return;
        UserInteraction::whereIn('id', $interactionIds)->update(['processed_at' => Carbon::now()]);
    }

    private function normalizeVector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));
        if ($magnitude == 0) return $vector;
        return array_map(fn($x) => $x / $magnitude, $vector);
    }
}
