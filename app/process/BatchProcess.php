<?php

namespace app\process;

use app\helper\LogHelper;
use app\helper\PerformanceTracker;
use app\services\RecommendationService;
use Throwable;
use Workerman\Timer;

/**
 * Proceso de fondo para ejecutar el cálculo de recomendaciones de forma periódica.
 */
class BatchProcess
{
    private ?RecommendationService $recommendationService = null;

    /**
     * Se ejecuta cuando el worker inicia.
     */
    public function onWorkerStart(): void
    {
        // El bootstrap de la BD y config ya debería estar cargado por Webman.
        // Para evitar que el proceso se ejecute inmediatamente y colisione con el arranque,
        // esperamos unos segundos antes de la primera ejecución.
        Timer::add(5, function () {
            $this->recommendationService = new RecommendationService();

            // Ejecutar el ciclo de inmediato una vez.
            $this->runCycle();

            // Programar ejecuciones periódicas (cada 5 minutos).
            Timer::add(300, [$this, 'runCycle']);
        }, [], false); // Ejecutar solo una vez
    }

    /**
     * Contiene la lógica del ciclo de ejecución del proceso batch.
     */
    public function runCycle(): void
    {
        LogHelper::info('batch-process', 'Iniciando nuevo ciclo de proceso batch...');

        try {
            PerformanceTracker::measure('total_batch_cycle', function () {
                $this->recommendationService->runBatchProcess();
            });
            LogHelper::info('batch-process', "Ciclo de proceso batch completado.");
        } catch (Throwable $e) {
            LogHelper::error('batch-process', 'Error crítico en el ciclo de proceso batch.', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString()
            ]);
        }
    }
}
