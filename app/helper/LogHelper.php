<?php

namespace app\helper; // <-- Corregido de app\helpers a app\helper

use Monolog\Logger;
use support\Log;

/**
 * Class LogHelper
 * Helper para un logging estructurado y centralizado.
 */
class LogHelper
{
    /**
     * Registra un mensaje en un canal específico.
     *
     * @param string $channel El canal de log (definido en config/log.php).
     * @param int $level El nivel de severidad (ej. Logger::INFO).
     * @param string $message El mensaje a registrar.
     * @param array $context Datos adicionales para el log.
     */
    public static function log(string $channel, int $level, string $message, array $context = []): void
    {
        try {
            Log::channel($channel)->log($level, $message, $context);
        } catch (\Throwable $e) {
            // Falla silenciosamente o loguea en el canal por defecto si el canal especificado no existe.
            Log::error("Error de logging en el canal '$channel': " . $e->getMessage(), [
                'original_message' => $message,
                'original_context' => $context
            ]);
        }
    }

    /**
     * Helper para logs de nivel INFO.
     *
     * @param string $channel
     * @param string $message
     * @param array $context
     */
    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log($channel, Logger::INFO, $message, $context);
    }

    /**
     * Helper para logs de nivel WARNING.
     *
     * @param string $channel
     * @param string $message
     * @param array $context
     */
    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::log($channel, Logger::WARNING, $message, $context);
    }

    /**
     * Helper para logs de nivel ERROR.
     *
     * @param string $channel
     * @param string $message
     * @param array $context
     */
    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log($channel, Logger::ERROR, $message, $context);
    }

    /**
     * Helper para logs de nivel DEBUG.
     *
     * @param string $channel
     * @param string $message
     * @param array $context
     */
    public static function debug(string $channel, string $message, array $context = []): void
    {
        self::log($channel, Logger::DEBUG, $message, $context);
    }
}
