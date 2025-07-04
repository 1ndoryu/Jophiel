<?php

namespace app\commands;

use app\services\RecommendationService;
use app\helper\LogHelper;

class UserRecalculateCommand
{
    /**
     * Ejecuta el recalculo completo para un usuario específico.
     *
     * Uso:
     *   php start.php user:recalc --user=123
     *   o
     *   php start.php user:recalc 123
     *
     * @param array $options Parámetros recibidos desde CLI.
     */
    public function run(array $options): void
    {
        // Soportamos tanto "--user" como "--id" o un argumento posicional
        $userId = null;
        $forceReset = false;

        if (isset($options['force'])) {
            $forceReset = true;
            unset($options['force']); // evitar confusión con parsing posicional
        }

        if (isset($options['user'])) {
            $userId = (int) $options['user'];
        } elseif (isset($options['id'])) {
            $userId = (int) $options['id'];
        } elseif (!empty($options)) {
            // Primer argumento posicional
            $userId = (int) array_shift($options);
        }

        if (!$userId) {
            echo "Error: Debe especificar un user_id. Ejemplo: php start.php user:recalc --user=123\n";
            return;
        }

        echo "Iniciando recalculo completo para el usuario ID {$userId}" . ($forceReset ? " (force reset)" : "") . "...\n";
        LogHelper::info('user-recalc-command', 'Inicio de comando.', ['user_id' => $userId]);

        $startTime = microtime(true);

        try {
            $service = new RecommendationService();
            $service->runFullRecalculationForUser($userId, $forceReset);

            $duration = microtime(true) - $startTime;
            echo "Recalculo completado en " . round($duration, 4) . " segundos.\n";
            LogHelper::info('user-recalc-command', 'Completado.', [
                'user_id' => $userId,
                'duration_seconds' => round($duration, 4)
            ]);
        } catch (\Throwable $e) {
            $message = "Error durante el recalculo completo del usuario.";
            echo $message . "\n";
            echo "Detalle: " . $e->getMessage() . "\n";

            LogHelper::error('user-recalc-command', $message, [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
