<?php
// Jophiel/app/controller/SyncController.php

namespace app\controller;

use app\model\SampleVector;
use app\model\User;
use app\model\UserFollow;
use app\model\UserInteraction;
use support\Request;
use support\Response;
use Throwable;
use app\helper\LogHelper;
use Illuminate\Database\Capsule\Manager as DB;

class SyncController
{
    private const LOG_CHANNEL = 'sync-controller-debug';

    // --- Métodos Públicos para Rutas Explícitas ---

    public function getSamplesChecksum(Request $request): Response
    {
        return $this->handleChecksumRequest($request, 'samples');
    }

    public function getSamplesIds(Request $request): Response
    {
        return $this->handleIdsRequest($request, 'samples');
    }

    public function getUsersChecksum(Request $request): Response
    {
        return $this->handleChecksumRequest($request, 'users');
    }

    public function getUsersIds(Request $request): Response
    {
        return $this->handleIdsRequest($request, 'users');
    }

    public function getLikesChecksum(Request $request): Response
    {
        return $this->handleChecksumRequest($request, 'likes');
    }

    public function getLikesIds(Request $request): Response
    {
        return $this->handleIdsRequest($request, 'likes');
    }

    public function getFollowsChecksum(Request $request): Response
    {
        return $this->handleChecksumRequest($request, 'follows');
    }

    public function getFollowsIds(Request $request): Response
    {
        return $this->handleIdsRequest($request, 'follows');
    }

    // --- Lógica Privada y Reutilizable ---

    /**
     * Calcula y devuelve un checksum para un tipo de dato específico.
     */
    private function handleChecksumRequest(Request $request, string $type): Response
    {
        LogHelper::info(self::LOG_CHANNEL, "Solicitud de checksum recibida.", ['type' => $type, 'uri' => $request->uri()]);

        try {
            $data = $this->getDataForType($type, true); // true para ordenar
            $count = count($data);
            $checksum = $count > 0 ? hash('sha256', implode(',', $data)) : null;

            LogHelper::info(self::LOG_CHANNEL, "Checksum generado con éxito.", [
                'type' => $type,
                'item_count' => $count,
                'checksum' => $checksum
            ]);

            return $this->jsonSuccessResponse(['checksum' => $checksum, 'count' => $count]);
        } catch (Throwable $e) {
            LogHelper::error(self::LOG_CHANNEL, "Error al generar checksum para '{$type}'.", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->jsonErrorResponse("Error al generar el checksum para '{$type}'.", 500);
        }
    }

    /**
     * Devuelve una lista completa de IDs o relaciones para un tipo de dato.
     */
    private function handleIdsRequest(Request $request, string $type): Response
    {
        LogHelper::info(self::LOG_CHANNEL, "Solicitud de IDs recibida.", ['type' => $type, 'uri' => $request->uri()]);

        try {
            $data = $this->getDataForType($type, false); // false para no ordenar (más rápido)
            $count = count($data);

            LogHelper::info(self::LOG_CHANNEL, "Lista de IDs obtenida con éxito.", [
                'type' => $type,
                'item_count' => $count
            ]);

            return $this->jsonSuccessResponse([$type => $data]);
        } catch (Throwable $e) {
            LogHelper::error(self::LOG_CHANNEL, "Error al obtener la lista de IDs para '{$type}'.", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->jsonErrorResponse("Error al obtener la lista de IDs para '{$type}'.", 500);
        }
    }

    private function getDataForType(string $type, bool $ordered): array
    {
        try {
            return $this->performQuery($type, $ordered);
        } catch (Throwable $e) {
            LogHelper::warning(self::LOG_CHANNEL, 'Fallo en la consulta inicial, reintentando conexión a la base de datos.', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            DB::connection()->reconnect();
            return $this->performQuery($type, $ordered);
        }
    }

    private function performQuery(string $type, bool $ordered): array
    {
        switch ($type) {
            case 'samples':
                $query = SampleVector::query();
                if ($ordered) $query->orderBy('sample_id', 'asc');
                return $query->pluck('sample_id')->all();

            case 'users':
                $query = User::query();
                if ($ordered) $query->orderBy('id', 'asc');
                return $query->pluck('id')->all();

            case 'likes':
                $query = UserInteraction::where('interaction_type', 'like');
                if ($ordered) $query->orderBy('user_id', 'asc')->orderBy('sample_id', 'asc');
                return $query->select('user_id', 'sample_id')
                    ->get()
                    ->map(fn($l) => "{$l->user_id}-{$l->sample_id}")
                    ->all();

            case 'follows':
                $query = UserFollow::query();
                if ($ordered) $query->orderBy('user_id', 'asc')->orderBy('followed_user_id', 'asc');
                return $query->select('user_id', 'followed_user_id')
                    ->get()
                    ->map(fn($f) => "{$f->user_id}-{$f->followed_user_id}")
                    ->all();

            default:
                LogHelper::warning(self::LOG_CHANNEL, 'Se solicitó un tipo de dato de sincronización no válido.', ['type' => $type]);
                throw new \InvalidArgumentException("Tipo de dato de sincronización no válido: {$type}");
        }
    }

    private function jsonSuccessResponse(array $data): Response
    {
        $payload = json_encode(['success' => true, 'data' => $data]);
        return new Response(200, ['Content-Type' => 'application/json'], $payload);
    }

    private function jsonErrorResponse(string $message, int $statusCode = 400): Response
    {
        $payload = json_encode(['success' => false, 'message' => $message]);
        return new Response($statusCode, ['Content-Type' => 'application/json'], $payload);
    }
}