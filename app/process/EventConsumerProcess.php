<?php

namespace app\process;

use app\helper\LogHelper;
use app\services\QuickUpdateService;
use app\services\VectorizationService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;
use Workerman\Timer;

/**
 * Proceso de fondo para consumir eventos de RabbitMQ.
 */
class EventConsumerProcess
{
    private ?AMQPStreamConnection $connection = null;

    /**
     * Se ejecuta cuando el worker inicia.
     */
    public function onWorkerStart(): void
    {
        // Añadimos un timer para intentar la conexión. Si falla, reintentará.
        Timer::add(1, function () {
            $this->connectAndConsume();
        }, [], false); // Se ejecuta solo una vez. Los reintentos se manejan dentro.
    }

    private function connectAndConsume(): void
    {
        // CORRECCIÓN: Definir $config antes del bloque try para que sea accesible en el catch.
        $config = config('rabbitmq');
        $log_channel = $config['log_channel'] ?? 'rabbitmq-consumer'; // Usar un valor por defecto seguro.

        try {
            $this->connection = new AMQPStreamConnection(
                $config['default']['host'],
                $config['default']['port'],
                $config['default']['user'],
                $config['default']['password'],
                $config['default']['vhost']
            );
            $channel = $this->connection->channel();

            LogHelper::info($log_channel, 'Conexión a RabbitMQ establecida.');

            $exchange = $config['jophiel_consumer']['exchange_name'];
            $queue = $config['jophiel_consumer']['queue_name'];
            $routingKeys = $config['jophiel_consumer']['routing_keys'];

            $channel->exchange_declare($exchange, 'topic', false, true, false);
            $channel->queue_declare($queue, false, true, false, false);

            foreach ($routingKeys as $routingKey) {
                $channel->queue_bind($queue, $exchange, $routingKey);
            }

            // CORRECCIÓN: Pasar $config al scope de la función anónima con `use`.
            $callback = function ($msg) use ($config, $log_channel) {
                try {
                    $this->handleMessage($msg->body);
                    $msg->ack(); // Confirma que el mensaje fue procesado.
                } catch (Throwable $e) {
                    // Ahora $log_channel y $config están definidos y son accesibles aquí.
                    LogHelper::error($log_channel, 'Error procesando mensaje', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $msg->nack(); // Rechaza el mensaje, podría ir a una dead-letter-queue.
                }
            };

            $channel->basic_consume($queue, '', false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } catch (Throwable $e) {
            // Ahora $log_channel está definido y accesible aquí.
            LogHelper::error($log_channel, 'No se pudo conectar a RabbitMQ. Reintentando en 10 segundos.', [
                'error' => $e->getMessage()
            ]);
            $this->closeConnection();
            Timer::add(10, [$this, 'connectAndConsume'], [], false);
        }
    }

    /**
     * Centraliza el ruteo de mensajes al servicio apropiado.
     */
    private function handleMessage(string $messageBody): void
    {
        $log_channel = config('rabbitmq.log_channel', 'rabbitmq-consumer');
        LogHelper::info($log_channel, 'Mensaje recibido', ['body' => $messageBody]);

        $decoded = json_decode($messageBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['event_name'], $decoded['payload'])) {
            LogHelper::warning($log_channel, 'Mensaje con formato JSON inválido o incompleto.', ['body' => $messageBody]);
            return;
        }

        $payload = $decoded['payload'];
        $eventName = $decoded['event_name'];

        switch ($eventName) {
            case 'user.interaction.like':
                (new QuickUpdateService())->handleLike($payload['user_id'], $payload['sample_id']);
                break;

            case 'user.interaction.follow':
                (new QuickUpdateService())->handleFollow($payload['user_id'], $payload['followed_user_id']);
                break;

            case 'sample.lifecycle.created':
                $metadata = $payload['metadata'];
                $metadata['media_id'] = $payload['sample_id'];
                $metadata['creator_id'] = $payload['creator_id'];
                (new VectorizationService())->processAndStore($metadata);
                break;

            case 'sample.lifecycle.deleted':
                (new VectorizationService())->deleteSampleData($payload['sample_id']);
                break;

            // TODO: Añadir casos para otros eventos (share, comment, etc.)

            default:
                LogHelper::info($log_channel, 'Evento no manejado recibido.', ['event_name' => $eventName]);
        }
    }

    public function onWorkerStop(): void
    {
        $this->closeConnection();
    }

    private function closeConnection(): void
    {
        try {
            if ($this->connection && $this->connection->isConnected()) {
                $this->connection->close();
            }
        } catch (Throwable $e) {
            // Ignorar errores al cerrar.
        }
        $this->connection = null;
    }
}
