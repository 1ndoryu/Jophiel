<?php

namespace app\controller;

use app\model\UserTasteProfile;
use app\services\TasteProfileService;
use support\Request;
use support\Response;
use app\helper\PerformanceTracker;
use app\helper\LogHelper;
use Throwable;

class TasteController
{
    private TasteProfileService $tasteService;

    public function __construct()
    {
        $this->tasteService = new TasteProfileService();
    }

    /**
     * Devuelve un resumen legible de los gustos del usuario.
     * GET /v1/taste/{user_id}
     */
    public function get(Request $request, int $user_id): Response
    {
        return PerformanceTracker::measure('taste_profile_request', function () use ($user_id) {
            try {
                $profile = UserTasteProfile::find($user_id);
                if (!$profile) {
                    return new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => 'Perfil de gusto no encontrado para el usuario.']));
                }

                $decoded = $this->tasteService->decode($profile);

                LogHelper::info('default', 'Taste profile recuperado', ['user_id' => $user_id]);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($decoded));
            } catch (Throwable $e) {
                LogHelper::error('default', 'Error al obtener taste profile', [
                    'user_id' => $user_id,
                    'error'   => $e->getMessage(),
                ]);
                return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => 'Ha ocurrido un error interno al obtener el taste profile.']));
            }
        }, ['user_id' => $user_id]);
    }
} 