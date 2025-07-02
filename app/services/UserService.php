<?php
// NUEVO ARCHIVO: app/services/UserService.php

namespace app\services;

use app\helper\LogHelper;
use app\model\User;
use app\model\UserFeedRecommendation;
use app\model\UserFollow;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * Servicio para gestionar el ciclo de vida de los usuarios en Jophiel.
 */
class UserService
{
    private string $logChannel = 'user-lifecycle';

    /**
     * Maneja el evento de creación de un nuevo usuario.
     * Crea el registro en la tabla 'users' y su perfil de gustos inicial.
     *
     * @param array $payload Datos del usuario desde el evento.
     */
    public function handleUserCreated(array $payload): void
    {
        if (empty($payload['user_id'])) {
            LogHelper::warning($this->logChannel, 'Evento user.created sin user_id.', ['payload' => $payload]);
            return;
        }

        $userId = $payload['user_id'];

        try {
            DB::transaction(function () use ($userId, $payload) {
                // Crear o actualizar el usuario
                User::updateOrCreate(
                    ['id' => $userId],
                    [
                        'name' => $payload['username'] ?? 'User ' . $userId,
                        'email' => $payload['email'] ?? "user{$userId}@email.com",
                    ]
                );

                // Crear su perfil de gustos si no existe
                $dimension = config('vectorization.vector_dimension', 35);
                UserTasteProfile::firstOrCreate(
                    ['user_id' => $userId],
                    ['taste_vector' => array_fill(0, $dimension, 0.0)]
                );
            });

            LogHelper::info($this->logChannel, 'Usuario creado o actualizado en Jophiel.', ['user_id' => $userId]);
        } catch (Throwable $e) {
            LogHelper::error($this->logChannel, 'Error al procesar user.created.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Maneja la eliminación de un usuario y todos sus datos asociados.
     *
     * @param int $userId ID del usuario a eliminar.
     */
    public function handleUserDeleted(int $userId): void
    {
        try {
            DB::transaction(function () use ($userId) {
                User::where('id', $userId)->delete();
                UserTasteProfile::where('user_id', $userId)->delete();
                UserInteraction::where('user_id', $userId)->delete();
                UserFollow::where('user_id', $userId)->orWhere('followed_user_id', $userId)->delete();
                UserFeedRecommendation::where('user_id', $userId)->delete();
            });

            LogHelper::info($this->logChannel, 'Usuario y todos sus datos asociados han sido eliminados de Jophiel.', ['user_id' => $userId]);
        } catch (Throwable $e) {
            LogHelper::error($this->logChannel, 'Error al procesar user.deleted.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
