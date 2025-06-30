--- ARCHIVO: app/database/seed.php ---
#!/usr/bin/env php
<?php
/**
 * Script para poblar la base de datos con datos de prueba para Jophiel.
 * Uso: php app/database/seed.php
 */

// Bootstrap de la aplicación para acceder a los modelos y la configuración.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../support/bootstrap.php';

use app\model\UserTasteProfile;
use app\model\SampleVector;
use app\model\UserInteraction;
use Illuminate\Database\Capsule\Manager as DB;
use Faker\Factory as FakerFactory;

// --- Configuración del Generador ---
const USERS_TO_CREATE = 50;
const SAMPLES_TO_CREATE = 500;
const INTERACTIONS_TO_CREATE = 5000;
const VECTOR_DIMENSION = 10; // Dimensión de los vectores de prueba.

$faker = FakerFactory::create();

echo "Iniciando el proceso de seeding para Jophiel...\n";

// --- Limpieza de Tablas ---
echo "Limpiando datos antiguos...\n";
DB::statement('TRUNCATE TABLE user_interactions, user_taste_profiles, sample_vectors, user_feed_recommendations, recommendation_cache RESTART IDENTITY');


// --- Generación de Usuarios y Perfiles ---
$userIds = [];
echo "Generando " . USERS_TO_CREATE . " usuarios y sus perfiles de gusto iniciales...\n";
$userProfiles = [];
for ($i = 1; $i <= USERS_TO_CREATE; $i++) {
    $userIds[] = $i;
    $userProfiles[] = [
        'user_id' => $i,
        'taste_vector' => json_encode(array_fill(0, VECTOR_DIMENSION, 0.0)), // Vector inicial neutro
        'updated_at' => now(),
    ];
}
UserTasteProfile::insert($userProfiles);
echo "Usuarios generados.\n";


// --- Generación de Samples y Vectores ---
$sampleIds = [];
echo "Generando " . SAMPLES_TO_CREATE . " samples y sus vectores...\n";
$sampleVectors = [];
for ($i = 1; $i <= SAMPLES_TO_CREATE; $i++) {
    $sampleIds[] = $i;
    $vector = [];
    for ($d = 0; $d < VECTOR_DIMENSION; $d++) {
        $vector[] = round($faker->randomFloat(4, -1, 1), 4); // Vector con valores entre -1 y 1
    }
    $sampleVectors[] = [
        'sample_id' => $i,
        'vector' => json_encode($vector),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}
SampleVector::insert($sampleVectors);
echo "Samples generados.\n";


// --- Generación de Interacciones ---
echo "Generando " . INTERACTIONS_TO_CREATE . " interacciones de usuarios...\n";
$interactions = [];
$interactionTypes = [
    'like' => 1.0,
    'play' => 0.2,
    'skip' => -0.1,
    'follow' => 0.8, // Simplificación: 'follow' se trata como una interacción con un sample.
    'dislike' => -1.0
];
$interactionKeys = array_keys($interactionTypes);

for ($i = 0; $i < INTERACTIONS_TO_CREATE; $i++) {
    $interactionType = $faker->randomElement($interactionKeys);
    $interactions[] = [
        'user_id' => $faker->randomElement($userIds),
        'sample_id' => $faker->randomElement($sampleIds),
        'interaction_type' => $interactionType,
        'weight' => $interactionTypes[$interactionType],
        'created_at' => $faker->dateTimeThisYear(),
    ];
}

// Insertar en lotes para mayor eficiencia
foreach (array_chunk($interactions, 500) as $chunk) {
    UserInteraction::insert($chunk);
}
echo "Interacciones generadas.\n";

echo "\n¡Seeding completado con éxito!\n";
echo "-------------------------------------\n";
echo "Total Usuarios: " . USERS_TO_CREATE . "\n";
echo "Total Samples: " . SAMPLES_TO_CREATE . "\n";
echo "Total Interacciones: " . INTERACTIONS_TO_CREATE . "\n";
echo "-------------------------------------\n";
