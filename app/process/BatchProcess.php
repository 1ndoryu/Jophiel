<?php

namespace app\process;

use app\helpers\LogHelper;
use app\services\RecommendationService;
use Monolog\Logger;
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
        $startTime = microtime(true);

        try {
            $this->recommendationService->runBatchProcess();

            $elapsed = round(microtime(true) - $startTime, 4);
            LogHelper::info('batch-process', "Ciclo de proceso batch completado en {$elapsed} segundos.");
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
