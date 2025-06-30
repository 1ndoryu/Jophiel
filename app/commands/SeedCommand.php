<?php

namespace app\commands;

use app\model\UserFollow;
use app\model\UserTasteProfile;
use app\model\SampleVector;
use app\model\UserInteraction;
use app\services\VectorizationService;
use Illuminate\Database\Capsule\Manager as DB;
use Faker\Factory as FakerFactory;
use Carbon\Carbon;

class SeedCommand
{
    /**
     * Ejecuta el proceso de seeding.
     *
     * @param array $options Opciones desde la línea de comandos (ej. ['users' => 10, 'samples' => 100]).
     */
    public function run(array $options): void
    {
        $usersCount = (int)($options['users'] ?? 50);
        $samplesCount = (int)($options['samples'] ?? 500);
        $interactionsCount = (int)($options['interactions'] ?? $samplesCount * 10); // Default a 10 interacciones por sample
        $followsCount = $usersCount * 5; // Cada usuario sigue a 5 otros en promedio

        echo "Iniciando el proceso de seeding para Jophiel con la siguiente configuración:\n";
        echo " - Usuarios:         $usersCount\n";
        echo " - Samples:          $samplesCount\n";
        echo " - Interacciones:    $interactionsCount\n";
        echo " - Seguimientos:     $followsCount\n";

        $faker = FakerFactory::create();
        $vectorizationService = new VectorizationService();
        $vectorConfig = config('vectorization');

        // --- Generación de Usuarios y Perfiles ---
        $userIds = [];
        echo "Generando $usersCount usuarios y sus perfiles de gusto iniciales...\n";
        $userProfiles = [];
        for ($i = 1; $i <= $usersCount; $i++) {
            $userIds[] = $i;
            $userProfiles[] = [
                'user_id' => $i,
                // CORRECCIÓN: Se debe codificar manualmente a JSON para inserciones masivas.
                'taste_vector' => json_encode(array_fill(0, $vectorConfig['vector_dimension'], 0.0)),
                'updated_at' => Carbon::now(),
            ];
        }
        UserTasteProfile::insert($userProfiles);
        echo "Usuarios generados.\n";

        // --- Generación de Samples y Vectores Realistas ---
        $sampleIds = [];
        echo "Generando $samplesCount samples con metadata realista y sus vectores...\n";
        $sampleVectors = [];
        for ($i = 1; $i <= $samplesCount; $i++) {
            $sampleIds[] = $i;
            $metadata = $this->generateRealisticMetadata($faker, $vectorConfig);
            $vector = $vectorizationService->vectorize($metadata);

            $sampleVectors[] = [
                'sample_id' => $i,
                'creator_id' => $faker->randomElement($userIds),
                'vector' => json_encode($vector), // Eloquent con insert masivo no usa casts, se necesita json_encode aquí
                'created_at' => Carbon::instance($faker->dateTimeThisYear()),
                'updated_at' => Carbon::now(),
            ];
        }
        SampleVector::insert($sampleVectors);
        echo "Samples generados.\n";

        // --- Generación de Interacciones ---
        echo "Generando $interactionsCount interacciones de usuarios...\n";
        $interactions = [];
        $interactionTypes = ['like' => 1.0, 'play' => 0.2, 'skip' => -0.1, 'follow' => 0.8, 'dislike' => -1.0];
        $interactionKeys = array_keys($interactionTypes);

        for ($i = 0; $i < $interactionsCount; $i++) {
            $interactionType = $faker->randomElement($interactionKeys);
            $interactions[] = [
                'user_id' => $faker->randomElement($userIds),
                'sample_id' => $faker->randomElement($sampleIds),
                'interaction_type' => $interactionType,
                'weight' => $interactionTypes[$interactionType],
                'created_at' => Carbon::instance($faker->dateTimeThisYear()),
            ];
        }

        foreach (array_chunk($interactions, 1000) as $chunk) {
            UserInteraction::insert($chunk);
        }
        echo "Interacciones generadas.\n";

        // --- Generación de Seguimientos (Follows) ---
        echo "Generando $followsCount relaciones de seguimiento...\n";
        $follows = [];
        $uniqueFollows = [];
        for ($i = 0; $i < $followsCount; $i++) {
            $follower = $faker->randomElement($userIds);
            $followed = $faker->randomElement($userIds);

            if ($follower === $followed) continue; // Un usuario no puede seguirse a sí mismo
            
            $key = "$follower-$followed";
            if (isset($uniqueFollows[$key])) continue; // Evitar duplicados

            $follows[] = ['user_id' => $follower, 'followed_user_id' => $followed, 'created_at' => Carbon::now()];
            $uniqueFollows[$key] = true;
        }
        UserFollow::insert($follows);
        echo "Seguimientos generados.\n";

        echo "\n¡Seeding completado con éxito!\n";
    }

    /**
     * Genera un array de metadata para un sample, usando los vocabularios de la configuración.
     */
    private function generateRealisticMetadata(\Faker\Generator $faker, array $config): array
    {
        return [
            'bpm' => $faker->numberBetween(
                $config['numerical_features']['bpm']['min'],
                $config['numerical_features']['bpm']['max']
            ),
            'genero' => $faker->randomElements($config['categorical_features']['genero'], $faker->numberBetween(1, 2)),
            'emocion_es' => $faker->randomElements($config['categorical_features']['emocion_es'], $faker->numberBetween(1, 3)),
            'instrumentos' => $faker->randomElements($config['categorical_features']['instrumentos'], $faker->numberBetween(1, 2)),
            'tipo' => [$faker->randomElement($config['categorical_features']['tipo'])],
        ];
    }
}