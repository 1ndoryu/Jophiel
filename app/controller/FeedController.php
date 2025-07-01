<?php

namespace app\controller;

use app\model\UserFeedRecommendation;
use support\Request;
use support\Response;
use support\Log;
use Throwable;
use Carbon\Carbon;

class FeedController
{
    /**
     * Retrieves the recommended feed for a given user.
     *
     * @param Request $request
     * @param int $user_id
     * @return Response
     */
    public function get(Request $request, int $user_id): Response
    {
        try {
            $recommendations = UserFeedRecommendation::where('user_id', $user_id)
                ->orderBy('score', 'desc')
                ->limit(200) // Consistent with FEED_SIZE in services
                ->pluck('sample_id');

            $response_payload = [
                'user_id' => (int)$user_id,
                'generated_at' => Carbon::now()->toIso8601String(),
                'sample_ids' => $recommendations->all(),
            ];

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($response_payload));
        } catch (Throwable $e) {
            Log::channel('default')->error('Error retrieving feed for user', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            $error_response = [
                'error' => 'An internal error occurred while generating the feed.'
            ];
            return new Response(500, ['Content-Type' => 'application/json'], json_encode($error_response));
        }
    }
}
