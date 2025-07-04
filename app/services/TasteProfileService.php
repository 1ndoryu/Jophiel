<?php

namespace app\services;

use app\model\UserTasteProfile;

/**
 * Servicio utilitario para transformar el vector de gustos del usuario
 * en un formato legible (mapa caracteristica => valor).
 */
class TasteProfileService
{
    private array $config;
    private array $featureMap;

    public function __construct()
    {
        $this->config = config('vectorization');
        $this->buildFeatureMap();
    }

    /**
     * Devuelve el desglose entendible del vector de gustos.
     *
     * @param UserTasteProfile $profile
     * @return array
     */
    public function decode(UserTasteProfile $profile): array
    {
        $vector = $profile->taste_vector;
        $result = [
            'user_id' => $profile->user_id,
            'bpm_preference' => $this->decodeBpm($vector),
        ];

        foreach ($this->config['categorical_features'] as $featureKey => $vocabulary) {
            $featureScores = [];
            foreach ($vocabulary as $term) {
                $idx = $this->featureMap[$featureKey][$term] ?? null;
                if ($idx !== null) {
                    $featureScores[$term] = round($vector[$idx] ?? 0.0, 4);
                }
            }
            $result[$featureKey] = $featureScores;
        }

        if (isset($this->featureMap['tags_hash'])) {
            $hashOffset = $this->featureMap['tags_hash'];
            $hashDim = $this->config['hash_dimension'];
            $hashSlice = array_slice($vector, $hashOffset, $hashDim);
            $nonZero = [];
            foreach ($hashSlice as $i => $val) {
                if (abs($val) > 1e-6) {
                    $nonZero[$i] = round($val, 4);
                }
            }
            if ($nonZero) {
                $result['tags_hash_buckets'] = $nonZero;
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------- */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------- */

    private function buildFeatureMap(): void
    {
        $this->featureMap = [];
        $offset = 0;
        $this->featureMap['bpm'] = $offset++;

        foreach ($this->config['categorical_features'] as $feature => $vocabulary) {
            $this->featureMap[$feature] = [];
            foreach ($vocabulary as $term) {
                $this->featureMap[$feature][$term] = $offset++;
            }
        }

        if (isset($this->config['hash_dimension'])) {
            $this->featureMap['tags_hash'] = $offset;
        }
    }

    private function decodeBpm(array $vector): ?float
    {
        $norm = $vector[$this->featureMap['bpm']] ?? null;
        if ($norm === null) return null;
        $min = $this->config['numerical_features']['bpm']['min'];
        $max = $this->config['numerical_features']['bpm']['max'];
        return round($norm * ($max - $min) + $min, 2);
    }
} 