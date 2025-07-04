<?php

namespace app\services;

use app\helper\LogHelper;
use app\model\SampleVector;
use app\model\UserFeedRecommendation;
use app\model\UserInteraction;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Servicio para convertir la metadata de un sample en un vector numérico.
 */
class VectorizationService
{
    private array $config;
    private array $featureMap;
    private int $dimension;

    public function __construct()
    {
        $this->config = config('vectorization');
        $this->dimension = $this->config['vector_dimension'];
        $this->buildFeatureMap();
    }

    /**
     * Construye un mapa que asocia cada característica con su posición en el vector final.
     */
    private function buildFeatureMap(): void
    {
        $this->featureMap = [];
        $offset = 0;

        // Posición para BPM
        $this->featureMap['bpm'] = $offset;
        $offset += 1;

        // Posiciones para cada término en los vocabularios categóricos
        foreach ($this->config['categorical_features'] as $feature => $vocabulary) {
            $this->featureMap[$feature] = [];
            foreach ($vocabulary as $term) {
                $this->featureMap[$feature][$term] = $offset;
                $offset++;
            }
        }

        // Posiciones para buckets de hashing de tags (Hashing Trick)
        if (isset($this->config['hash_dimension'])) {
            $this->featureMap['tags_hash'] = $offset;
            $offset += $this->config['hash_dimension'];
        }
    }

    /**
     * Procesa la metadata de un sample y la guarda como un vector en la base de datos.
     *
     * @param array $metadata El array asociativo con la metadata del sample (ej. de casiel).
     * @return SampleVector|null El modelo del vector guardado o null si falla.
     */
    public function processAndStore(array $metadata): ?SampleVector
    {
        if (empty($metadata['media_id']) || empty($metadata['creator_id'])) {
            LogHelper::warning('vectorization_service', 'Metadata sin media_id o creator_id, no se puede procesar.', ['metadata' => $metadata]);
            return null;
        }

        $vectorArray = $this->vectorize($metadata);

        $isCreating = !SampleVector::where('sample_id', $metadata['media_id'])->exists();
        LogHelper::info(
            'vectorization_service',
            ($isCreating ? 'Creando nuevo' : 'Actualizando') . ' vector de sample.',
            ['sample_id' => $metadata['media_id']]
        );

        // Usamos updateOrCreate para manejar tanto la creación inicial como futuras actualizaciones.
        $sampleVector = SampleVector::updateOrCreate(
            ['sample_id' => $metadata['media_id']],
            [
                'creator_id' => $metadata['creator_id'],
                'vector' => $vectorArray
            ]
        );

        return $sampleVector;
    }

    /**
     * Elimina todos los datos asociados a un sample_id del sistema.
     *
     * @param int $sampleId
     * @return void
     */
    public function deleteSampleData(int $sampleId): void
    {
        LogHelper::info('vectorization_service', 'Iniciando eliminación de datos para el sample.', ['sample_id' => $sampleId]);
        try {
            DB::transaction(function () use ($sampleId) {
                // Eliminar de la tabla principal de vectores
                SampleVector::where('sample_id', $sampleId)->delete();

                // Eliminar de todos los feeds de recomendación pre-calculados
                UserFeedRecommendation::where('sample_id', $sampleId)->delete();

                // Eliminar el historial de interacciones con este sample
                UserInteraction::where('sample_id', $sampleId)->delete();

                // TODO: Considerar limpiar la tabla `recommendation_cache` si es relevante en el futuro.
            });
            LogHelper::info('vectorization_service', 'Datos del sample eliminados con éxito.', ['sample_id' => $sampleId]);
        } catch (\Throwable $e) {
            LogHelper::error('vectorization_service', 'Error al eliminar datos del sample.', [
                'sample_id' => $sampleId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Convierte un array de metadata en un vector numérico.
     *
     * @param array $metadata
     * @return array
     */
    public function vectorize(array $metadata): array
    {
        // 1. Inicializar el vector con ceros.
        $vector = array_fill(0, $this->dimension, 0.0);

        // 2. Procesar características numéricas (BPM).
        if (isset($metadata['bpm'])) {
            $vector[$this->featureMap['bpm']] = $this->normalizeBpm($metadata['bpm']);
        }

        // 3. Procesar características categóricas (multi-hot encoding).
        foreach ($this->config['categorical_features'] as $featureKey => $vocabulary) {
            if (isset($metadata[$featureKey]) && is_array($metadata[$featureKey])) {
                foreach ($metadata[$featureKey] as $term) {
                    // Si el término existe en nuestro vocabulario, marcamos su posición con 1.0.
                    if (isset($this->featureMap[$featureKey][$term])) {
                        $index = $this->featureMap[$featureKey][$term];
                        $vector[$index] = 1.0;
                    }
                }
            }
        }

        // 4. Procesar tags con Hashing Trick
        if (isset($this->config['hash_dimension']) && isset($metadata['tags']) && is_array($metadata['tags'])) {
            $hashDim = $this->config['hash_dimension'];
            $hashOffset = $this->featureMap['tags_hash'] ?? null;
            if ($hashOffset !== null) {
                foreach ($metadata['tags'] as $tag) {
                    $bucket = abs(crc32($tag)) % $hashDim;
                    $index = $hashOffset + $bucket;
                    $vector[$index] = 1.0;
                }
            }
        }

        return $vector;
    }

    /**
     * Normaliza el BPM a un rango de [0, 1].
     *
     * @param int $bpm
     * @return float
     */
    private function normalizeBpm(int $bpm): float
    {
        $min = $this->config['numerical_features']['bpm']['min'];
        $max = $this->config['numerical_features']['bpm']['max'];

        if ($bpm <= $min) return 0.0;
        if ($bpm >= $max) return 1.0;

        return ($bpm - $min) / ($max - $min);
    }
}
