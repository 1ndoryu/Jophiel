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
    private const RECURRING_INTERVAL_SECONDS = 300; // 5 minutos

    /**
     * Se ejecuta cuando el worker inicia.
     */
    public function onWorkerStart(): void
    {
        // Instanciar el servicio.
        $this->recommendationService = new RecommendationService();

        // Ejecutar el primer ciclo de inmediato al arrancar.
        $this->runCycle();

        // Luego, programar las ejecuciones periódicas recurrentes.
        Timer::add(self::RECURRING_INTERVAL_SECONDS, [$this, 'runCycle']);
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