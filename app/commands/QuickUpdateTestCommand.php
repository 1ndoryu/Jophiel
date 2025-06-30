<?php

namespace app\commands;

use app\helper\LogHelper;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use app\services\QuickUpdateService; // <-- CAMBIO AQUÍ
use Illuminate\Database\Capsule\Manager as DB;

class QuickUpdateTestCommand
{
    public function run(array $options): void
    {
        echo "--- TEST DE REACCIÓN INMEDIATA (QUICK UPDATE) ---\n";

        // 1. Seleccionar un usuario y un sample al azar para la simulación
        $user = UserTasteProfile::inRandomOrder()->first();
        if (!$user) {
            echo "Error: No se encontraron usuarios en la base de datos. Ejecute 'jophiel db:seed' primero.\n";
            return;
        }

        // Buscar un sample con el que el usuario NO haya tenido una interacción definitiva
        $interactedSampleIds = UserInteraction::where('user_id', $user->user_id)
            ->whereIn('interaction_type', ['like', 'dislike'])
            ->pluck('sample_id');

        $sampleToLike = DB::table('sample_vectors')
            ->whereNotIn('sample_id', $interactedSampleIds)
            ->inRandomOrder()
            ->first();

        if (!$sampleToLike) {
            echo "Error: No se encontró un sample adecuado para la simulación de 'like'.\n";
            echo "Puede que el usuario de prueba ya haya interactuado con todos los samples.\n";
            return;
        }

        $userId = $user->user_id;
        $sampleId = $sampleToLike->sample_id;

        echo "Simulando 'like' del usuario ID: {$userId} al sample ID: {$sampleId}\n";
        LogHelper::info('quick-update-test', "Inicio de simulación.", ['user_id' => $userId, 'sample_id' => $sampleId]);

        // 2. Ejecutar la lógica de actualización rápida y medir el tiempo
        $startTime = microtime(true);

        try {
            $service = new QuickUpdateService(); // <-- CAMBIO AQUÍ
            $service->handleLike($userId, $sampleId); // <-- CAMBIO AQUÍ

            $duration = microtime(true) - $startTime;

            echo "\n--- RESULTADO DEL TEST ---\n";
            echo "Tiempo de ejecución de la actualización rápida: " . round($duration, 4) . " segundos.\n";
            echo "--------------------------\n";
            LogHelper::info('quick-update-test', "Simulación completada con éxito.", [
                'duration_seconds' => round($duration, 4)
            ]);
        } catch (\Throwable $e) {
            $message = "Error crítico durante el test de actualización rápida.";
            echo $message . "\n";
            echo "Error: " . $e->getMessage() . "\n";
            LogHelper::error('quick-update-test', $message, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
