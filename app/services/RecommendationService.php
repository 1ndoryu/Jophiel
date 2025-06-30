<?php

namespace app\services;

use app\helper\LogHelper;
use app\helper\PerformanceTracker;
use app\model\SampleVector;
use app\model\UserFeedRecommendation;
use app\model\UserFollow;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;

class RecommendationService
{
    private ScoreCalculationService $scoreService;
    private VectorizationService $vectorizationService;

    // --- Constantes de Configuración ---
    private const INTERACTION_BATCH_SIZE = 1000;
    private const PROFILE_UPDATE_LEARNING_RATE = 0.05;
    private const CANDIDATE_SAMPLES_LIMIT = 1000; // Límite de candidatos por pre-filtrado.
    private const FEED_SIZE = 200;
    private const TASTE_VECTOR_THRESHOLD = 0.1; // Umbral para considerar una característica como "importante" para un usuario.

    public function __construct()
    {
        $this->scoreService = new ScoreCalculationService();
        $this->vectorizationService = new VectorizationService();
    }

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

    private function getNewInteractions(): Collection
    {
        return UserInteraction::whereNull('processed_at')->orderBy('id')->limit(self::INTERACTION_BATCH_SIZE)->get();
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

            $tasteVector = $userProfile->taste_vector;

            foreach ($userInteractions as $interaction) {
                $sampleVectorModel = $sampleVectors->get($interaction->sample_id);
                if (!$sampleVectorModel) continue;

                $sampleVector = $sampleVectorModel->vector;
                $weight = (float) $interaction->weight;

                for ($i = 0; $i < count($tasteVector); $i++) {
                    $tasteVector[$i] = (1 - self::PROFILE_UPDATE_LEARNING_RATE) * $tasteVector[$i]
                        + self::PROFILE_UPDATE_LEARNING_RATE * $weight * $sampleVector[$i];
                }
            }

            $userProfile->taste_vector = $this->normalizeVector($tasteVector);
            $userProfile->save();
        }

        return $affectedUserIds;
    }

    private function recalculateFeedsForUsers(array $userIds): void
    {
        if (empty($userIds)) return;
        
        $userProfiles = UserTasteProfile::findMany($userIds)->keyBy('user_id');
        $allUserFollows = $this->getFollowedCreatorsForUsers($userIds);

        foreach ($userProfiles as $userId => $userProfile) {
            PerformanceTracker::measure('recalculate_feeds_single_user', function () use ($userId, $userProfile, $allUserFollows) {
                $followedCreators = $allUserFollows->get($userId, collect())->flip();
                $definitiveInteractions = $this->getUserDefinitiveInteractions($userId);

                $candidates = $this->getCandidateSamplesForUser($userProfile);
                if ($candidates->isEmpty()) return;

                $recommendations = $this->scoreCandidates($userProfile, $candidates, $definitiveInteractions, $followedCreators);
                
                usort($recommendations, fn($a, $b) => $b['score'] <=> $a['score']);
                $topRecommendations = array_slice($recommendations, 0, self::FEED_SIZE);

                $this->saveUserFeed($userId, $topRecommendations);

                LogHelper::info('batch-process', "Feed recalculado para el usuario ID: {$userId} con " . count($topRecommendations) . " recomendaciones.");
            }, ['user_id' => $userId]);
        }
    }

    private function getCandidateSamplesForUser(UserTasteProfile $userProfile): Collection
    {
        $tasteVector = $userProfile->taste_vector;
        $hotIndices = [];
        foreach ($tasteVector as $index => $value) {
            if ($value > self::TASTE_VECTOR_THRESHOLD) {
                $hotIndices[] = $index;
            }
        }

        if (empty($hotIndices)) {
            return SampleVector::inRandomOrder()->limit(self::CANDIDATE_SAMPLES_LIMIT)->get();
        }

        $query = SampleVector::query();
        $query->where(function ($q) use ($hotIndices) {
            foreach ($hotIndices as $index) {
                // Buscamos samples que tengan un 1 en alguna de las posiciones "calientes" del vector de gusto del usuario.
                // Esta sintaxis es segura porque $index es un entero.
                $q->orWhereRaw("vector->>".(int)$index." = '1'");
            }
        });

        return $query->inRandomOrder()->limit(self::CANDIDATE_SAMPLES_LIMIT)->get();
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
                    'user_id' => $userProfile->user_id,
                    'sample_id' => $sample->sample_id,
                    'score' => $score
                ];
            }
        }
        return $recommendations;
    }

    private function getUserDefinitiveInteractions(int $userId): array
    {
        return UserInteraction::where('user_id', $userId)
            ->whereIn('interaction_type', ['like', 'dislike'])
            ->pluck('sample_id')
            ->all();
    }

    private function getFollowedCreatorsForUsers(array $userIds): Collection
    {
        return UserFollow::whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id')
            ->map(fn($follows) => $follows->pluck('followed_user_id'));
    }

    private function saveUserFeed(int $userId, array $topRecommendations): void
    {
        PerformanceTracker::measure('save_recommendations', function () use ($userId, $topRecommendations) {
            DB::transaction(function () use ($userId, $topRecommendations) {
                UserFeedRecommendation::where('user_id', $userId)->delete();
                if (!empty($topRecommendations)) {
                    $now = Carbon::now();
                    $insertData = array_map(fn($rec) => $rec + ['generated_at' => $now], $topRecommendations);
                    UserFeedRecommendation::insert($insertData);
                }
            });
        }, ['user_id' => $userId, 'recommendation_count' => count($topRecommendations)]);
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