<?php

namespace app\controller;

use app\model\UserFeedRecommendation;
use support\Request;
use support\Response;
use support\Log;
use Throwable;
use Carbon\Carbon;
use app\helper\PerformanceTracker;
use app\helper\LogHelper;
use app\model\SampleVector;

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
        return PerformanceTracker::measure('feed_request', function () use ($user_id, $request) {
            try {
                // -----------------------------
                // 1) Parámetros de paginación
                // -----------------------------
                $perPage = max(1, min((int)$request->input('per_page', 20), 100));
                $page    = max(1, (int)$request->input('page', 1));

                // -----------------------------
                // 2) Consulta base de recomendaciones
                // -----------------------------
                $query = UserFeedRecommendation::where('user_id', $user_id)
                    ->orderBy('score', 'desc');

                // Fallback para usuarios sin recomendaciones aún
                if (!$query->exists()) {
                    $query = SampleVector::orderBy('created_at', 'desc');
                }

                // -----------------------------
                // 3) Paginación
                // -----------------------------
                $paginator = $query->paginate($perPage, ['sample_id'], 'page', $page);

                // Extraer solo los IDs de sample
                $sampleIds = collect($paginator->items())->pluck('sample_id')->all();

                $response_payload = [
                    'user_id'       => (int)$user_id,
                    'generated_at'  => Carbon::now()->toIso8601String(),
                    'sample_ids'    => $sampleIds,
                    'pagination'    => [
                        'current_page'  => $paginator->currentPage(),
                        'per_page'      => $paginator->perPage(),
                        'total'         => $paginator->total(),
                        'last_page'     => $paginator->lastPage(),
                        'next_page_url' => $paginator->nextPageUrl(),
                        'prev_page_url' => $paginator->previousPageUrl(),
                    ],
                ];

                // Logueamos la generación del feed con detalles útiles.
                LogHelper::info('default', 'Feed generado para usuario', [
                    'user_id'        => $user_id,
                    'sample_count'   => count($response_payload['sample_ids']),
                ]);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($response_payload));
            } catch (Throwable $e) {
                LogHelper::error('default', 'Error al obtener el feed del usuario', [
                    'user_id' => $user_id,
                    'error'   => $e->getMessage(),
                ]);

                $error_response = [
                    'error' => 'Ha ocurrido un error interno al generar el feed.'
                ];
                return new Response(500, ['Content-Type' => 'application/json'], json_encode($error_response));
            }
        }, [
            'user_id' => $user_id,
        ]);
    }

    public function getWithScores(Request $request, int $user_id): Response
    {
        return PerformanceTracker::measure('feed_scores_request', function () use ($user_id, $request) {
            try {
                // -----------------------------
                // 1) Parámetros de paginación
                // -----------------------------
                $perPage = max(1, min((int)$request->input('per_page', 20), 100));
                $page    = max(1, (int)$request->input('page', 1));

                // -----------------------------
                // 2) Consulta base de recomendaciones (con score)
                // -----------------------------
                $query = UserFeedRecommendation::where('user_id', $user_id)
                    ->orderBy('score', 'desc');

                $hasScores = $query->exists();

                if (!$hasScores) {
                    // Fallback para usuarios sin recomendaciones aún
                    $query = SampleVector::orderBy('created_at', 'desc');
                }

                $columns = $hasScores ? ['sample_id', 'score'] : ['sample_id'];

                // -----------------------------
                // 3) Paginación
                // -----------------------------
                $paginator = $query->paginate($perPage, $columns, 'page', $page);

                // Formateamos la salida como pares id -> score
                $samples = collect($paginator->items())->map(function ($item) {
                    return [
                        'sample_id' => $item->sample_id,
                        'score'     => $item->score ?? null,
                    ];
                })->all();

                $response_payload = [
                    'user_id'       => (int)$user_id,
                    'generated_at'  => Carbon::now()->toIso8601String(),
                    'samples'       => $samples,
                    'pagination'    => [
                        'current_page'  => $paginator->currentPage(),
                        'per_page'      => $paginator->perPage(),
                        'total'         => $paginator->total(),
                        'last_page'     => $paginator->lastPage(),
                        'next_page_url' => $paginator->nextPageUrl(),
                        'prev_page_url' => $paginator->previousPageUrl(),
                    ],
                ];

                // Logueamos la generación del feed con puntuaciones
                LogHelper::info('default', 'Feed con puntuaciones generado para usuario', [
                    'user_id'      => $user_id,
                    'sample_count' => count($response_payload['samples']),
                ]);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($response_payload));
            } catch (Throwable $e) {
                LogHelper::error('default', 'Error al obtener el feed con puntuaciones', [
                    'user_id' => $user_id,
                    'error'   => $e->getMessage(),
                ]);

                $error_response = [
                    'error' => 'Ha ocurrido un error interno al generar el feed con puntuaciones.'
                ];
                return new Response(500, ['Content-Type' => 'application/json'], json_encode($error_response));
            }
        }, [
            'user_id' => $user_id,
        ]);
    }
}
