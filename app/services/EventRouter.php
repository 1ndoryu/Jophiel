<?php

namespace app\services;

use app\helper\LogHelper;
use Throwable;

/**
 * EventRouter centraliza el ruteo de eventos entrantes (RabbitMQ, tests, etc.)
 * hacia los servicios internos correspondientes.
 */
class EventRouter
{
    /**
     * Procesa un evento y delega al servicio adecuado.
     *
     * @param string $eventName  Nombre del evento (ej: 'user.interaction.like').
     * @param array  $payload    Datos específicos del evento.
     * @return void
     */
    public static function route(string $eventName, array $payload): void
    {
        $log_channel = 'event-router';
        LogHelper::info($log_channel, 'Enrutando evento.', [
            'event_name' => $eventName,
            'payload'    => $payload,
        ]);

        try {
            switch ($eventName) {
                case 'user.interaction.like':
                    (new QuickUpdateService())->handleLike($payload['user_id'], $payload['sample_id']);
                    break;

                case 'user.interaction.comment':
                    (new QuickUpdateService())->handleComment($payload['user_id'], $payload['sample_id']);
                    break;

                case 'user.interaction.follow':
                    (new QuickUpdateService())->handleFollow($payload['user_id'], $payload['followed_user_id']);
                    break;

                case 'user.interaction.unlike':
                    (new QuickUpdateService())->handleUnlike($payload['user_id'], $payload['sample_id']);
                    break;

                case 'user.interaction.unfollow':
                    (new QuickUpdateService())->handleUnfollow($payload['user_id'], $payload['unfollowed_user_id']);
                    break;

                case 'sample.lifecycle.created':
                case 'sample.lifecycle.updated':
                    $metadata                 = $payload['metadata'];
                    $metadata['media_id']     = $payload['sample_id'];
                    $metadata['creator_id']   = $payload['creator_id'];
                    (new VectorizationService())->processAndStore($metadata);
                    break;

                case 'sample.lifecycle.deleted':
                    (new VectorizationService())->deleteSampleData($payload['sample_id']);
                    break;

                default:
                    LogHelper::info($log_channel, 'Evento no manejado recibido.', ['event_name' => $eventName]);
            }
        } catch (Throwable $e) {
            LogHelper::error($log_channel, 'Error procesando evento.', [
                'event_name' => $eventName,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }
} 