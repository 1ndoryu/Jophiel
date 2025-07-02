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
        return PerformanceTracker::measure('feed_request', function () use ($user_id) {
            try {
                $recommendations = UserFeedRecommendation::where('user_id', $user_id)
                    ->orderBy('score', 'desc')
                    ->limit(200)
                    ->pluck('sample_id');

                // Si el usuario aún no tiene recomendaciones calculadas
                // (por ejemplo, es nuevo y no ha interactuado),
                // devolvemos un feed por defecto con los últimos samples creados.
                if ($recommendations->isEmpty()) {
                    $recommendations = \app\model\SampleVector::orderBy('created_at', 'desc')
                        ->limit(200)
                        ->pluck('sample_id');
                }

                $response_payload = [
                    'user_id'       => (int)$user_id,
                    'generated_at'  => Carbon::now()->toIso8601String(),
                    'sample_ids'    => $recommendations->all(),
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
}
