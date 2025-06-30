<?php

namespace app\commands;

use app\services\RecommendationService;
use app\helper\LogHelper;

class BatchCommand
{
    public function run(): void
    {
        echo "Iniciando ejecución manual del proceso batch de recomendaciones...\n";
        LogHelper::info('batch-command', 'Iniciando ejecución manual del ciclo.');

        try {
            $service = new RecommendationService();
            $service->runBatchProcess();
            $message = "Ejecución manual del proceso batch completada con éxito.";
            echo $message . "\n";
            LogHelper::info('batch-command', $message);
        } catch (\Throwable $e) {
            $message = "Error crítico durante la ejecución manual del proceso batch.";
            echo $message . "\n";
            echo "Error: " . $e->getMessage() . "\n";
            LogHelper::error('batch-command', $message, [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString()
            ]);
        }
    }
}
