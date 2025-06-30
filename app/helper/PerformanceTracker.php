<?php

namespace app\helper;

/**
 * Class PerformanceTracker
 * Un simple helper para medir y registrar tiempos de ejecución.
 */
class PerformanceTracker
{
    private const LOG_CHANNEL = 'performance';

    /**
     * Ejecuta una función y mide su tiempo de ejecución.
     *
     * @param string $key El identificador para la medición.
     * @param callable $callback La función a ejecutar.
     * @param array $context Contexto adicional para el log.
     * @return mixed El resultado de la función.
     */
    public static function measure(string $key, callable $callback, array $context = [])
    {
        $start = microtime(true);

        $result = $callback();

        $duration = microtime(true) - $start;

        $logMessage = "Performance metric for '{$key}'";
        $logContext = array_merge([
            'duration_seconds' => round($duration, 4),
        ], $context);

        // Usamos LogHelper para mantener la consistencia del logging
        LogHelper::info(self::LOG_CHANNEL, $logMessage, $logContext);

        return $result;
    }
}
