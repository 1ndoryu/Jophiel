<?php

namespace app\services;

use app\helper\PerformanceTracker;
use app\model\UserFeedRecommendation;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Gestiona el almacenamiento y la manipulación de los feeds de recomendación.
 */
class FeedManagerService
{
    /**
     * Guarda las recomendaciones finales para un usuario, reemplazando su feed anterior.
     *
     * @param int $userId
     * @param array $topRecommendations Array de recomendaciones, cada una con ['sample_id', 'score'].
     * @return void
     */
    public function saveUserFeed(int $userId, array $topRecommendations): void
    {
        PerformanceTracker::measure('save_recommendations', function () use ($userId, $topRecommendations) {
            DB::transaction(function () use ($userId, $topRecommendations) {
                UserFeedRecommendation::where('user_id', $userId)->delete();
                if (!empty($topRecommendations)) {
                    $now = Carbon::now();
                    // Aseguramos que el user_id está en cada elemento.
                    $insertData = array_map(function ($rec) use ($userId, $now) {
                        return [
                            'user_id' => $userId,
                            'sample_id' => $rec['sample_id'],
                            'score' => $rec['score'],
                            'generated_at' => $now
                        ];
                    }, $topRecommendations);

                    UserFeedRecommendation::insert($insertData);
                }
            });
        }, ['user_id' => $userId, 'recommendation_count' => count($topRecommendations)]);
    }
}
