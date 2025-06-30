<?php

namespace app\commands;

use app\services\RecommendationService;
use app\helper\LogHelper;
use app\commands\InstallerCommand;
use app\commands\SeedCommand;

class BatchCommand
{
    /**
     * Orquesta la ejecución del comando batch.
     * Puede ejecutar un ciclo normal o un benchmark completo.
     *
     * @param array $options Opciones desde CLI. Ej: ['users' => 100, 'samples' => 1000]
     */
    public function run(array $options = []): void
    {
        // Si se pasan opciones de benchmark, se ejecuta ese flujo.
        if (isset($options['users']) || isset($options['samples'])) {
            $this->runBenchmark($options);
            return;
        }

        // Flujo por defecto: ejecutar un único ciclo de recomendación.
        $this->runSingleCycle();
    }

    /**
     * Ejecuta un único ciclo del proceso batch sobre los datos existentes.
     */
    private function runSingleCycle(): void
    {
        echo "Iniciando ejecución manual del proceso batch de recomendaciones...\n";
        LogHelper::info('batch-command', 'Iniciando ejecución manual del ciclo.');
        $startTime = microtime(true);

        try {
            $service = new RecommendationService();
            $service->runBatchProcess();
            $duration = microtime(true) - $startTime;
            $message = "Ejecución manual del proceso batch completada en " . round($duration, 4) . " segundos.";
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

    /**
     * Ejecuta un flujo de benchmark completo: reset, seed, run y measure.
     */
    private function runBenchmark(array $options): void
    {
        $users = (int)($options['users'] ?? 50);
        $samples = (int)($options['samples'] ?? 500);
        $interactions = (int)($options['interactions'] ?? $samples * 10);

        echo "--- MODO BENCHMARK ---\n";
        echo "Este comando reiniciará la base de datos y la poblará con datos nuevos.\n";
        echo "Configuración: $users usuarios, $samples samples, $interactions interacciones.\n";
        echo "Presione Enter para continuar o Ctrl+C para cancelar...";
        fgets(STDIN);

        // 1. Reset DB
        echo "\n[1/4] Reiniciando la base de datos...\n";
        (new InstallerCommand())->reset(true); // true para modo no-interactivo

        // 2. Seed DB
        echo "[2/4] Poblando la base de datos con datos de prueba...\n";
        $seedOptions = ['users' => $users, 'samples' => $samples, 'interactions' => $interactions];
        (new SeedCommand())->run($seedOptions);

        // 3. Run Batch Process and time it
        echo "[3/4] Ejecutando el proceso batch y midiendo el rendimiento...\n";
        LogHelper::info('batch-benchmark', "Iniciando benchmark.", $seedOptions);
        $startTime = microtime(true);

        try {
            $service = new RecommendationService();
            $service->runBatchProcess();
            $duration = microtime(true) - $startTime;
            
            echo "[4/4] Proceso completado.\n";
            echo "\n--- RESULTADO DEL BENCHMARK ---\n";
            echo "Tiempo total de ejecución del proceso batch: " . round($duration, 4) . " segundos.\n";
            echo "---------------------------------\n";
            LogHelper::info('batch-benchmark', "Benchmark completado.", [
                'duration_seconds' => round($duration, 4)
            ]);

        } catch (\Throwable $e) {
             $message = "Error crítico durante el benchmark.";
            echo $message . "\n";
            echo "Error: " . $e->getMessage() . "\n";
            LogHelper::error('batch-benchmark', $message, [
                'message' => $e->getMessage(),'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
        }
    }
}