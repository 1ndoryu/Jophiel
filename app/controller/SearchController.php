<?php

namespace app\controller;

use app\services\SearchService;
use support\Request;
use support\Response;
use Carbon\Carbon;
use app\helper\LogHelper;
use app\helper\PerformanceTracker;

class SearchController
{
    /**
     * Endpoint GET /v1/search?q=term&user_id=123&page=1&per_page=20
     */
    public function search(Request $request): Response
    {
        return PerformanceTracker::measure('search_request', function () use ($request) {
            $query   = (string) $request->input('q', '');
            $userId  = (int) $request->input('user_id', 0);
            $perPage = max(1, min((int) $request->input('per_page', 20), 100));
            $page    = max(1, (int) $request->input('page', 1));

            if ($userId === 0) {
                return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                    'error' => 'Parámetro user_id es requerido y debe ser numérico.'
                ]));
            }

            $service  = new SearchService();
            $result   = $service->search($query, $userId, $page, $perPage);

            $sampleIds = array_column($result['results'], 'sample_id');

            $payload = [
                'user_id'      => $userId,
                'generated_at' => Carbon::now()->toIso8601String(),
                'sample_ids'   => $sampleIds,
                'pagination'   => [
                    'current_page'  => $result['page'],
                    'per_page'      => $result['per_page'],
                    'total'         => $result['total'],
                    'last_page'     => (int) ceil(max(1, $result['total']) / $result['per_page']),
                    'next_page_url' => null, // Construir si se requiere
                    'prev_page_url' => null,
                ],
            ];

            LogHelper::info('search', 'Búsqueda realizada', [
                'user_id'   => $userId,
                'query'     => $query,
                'results'   => $result['total'],
            ]);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload));
        }, []);
    }
} 