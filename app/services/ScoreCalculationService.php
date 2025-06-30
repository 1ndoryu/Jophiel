<?php

namespace app\services;

use app\model\SampleVector;
use app\model\UserTasteProfile;
use Carbon\Carbon;

/**
 * Servicio para calcular el ScoreFinal de una recomendación.
 * Encapsula la lógica de la fórmula de puntuación.
 */
class ScoreCalculationService
{
    /**
     * Ponderaciones para cada factor del score.
     */
    private const WEIGHT_SIMILARITY = 1.0;
    private const WEIGHT_FOLLOWING = 0.5;
    private const WEIGHT_NOVELTY = 0.2;
    public const PENALTY_DEFINITIVE_INTERACTION = -1000.0;

    /**
     * Calcula el ScoreFinal para un par usuario-sample.
     *
     * @param array $userTasteVector El vector de gustos del usuario.
     * @param SampleVector $sampleVector El vector del sample.
     * @param array $userDefinitiveInteractions Un array de sample_id con los que el usuario ya ha interactuado de forma "definitiva" (like/dislike).
     * @param bool $isFollowingCreator Si el usuario sigue al creador del sample.
     * @return float
     */
    public function calculateFinalScore(
        array $userTasteVector,
        SampleVector $sampleVector,
        array $userDefinitiveInteractions,
        bool $isFollowingCreator
    ): float {
        // 1. Factor de Penalización: Es lo primero que se revisa para descartar rápidamente.
        $penaltyFactor = $this->calculatePenaltyFactor($sampleVector->sample_id, $userDefinitiveInteractions);
        if ($penaltyFactor === self::PENALTY_DEFINITIVE_INTERACTION) {
            return self::PENALTY_DEFINITIVE_INTERACTION; // Si hay penalización, el score es definitivo y no se calcula nada más.
        }

        // 2. Factor de Similitud (Coseno)
        $similarityFactor = $this->calculateCosineSimilarity(
            $userTasteVector,
            $sampleVector->vector
        );

        // 3. Factor de Seguimiento
        $followingFactor = $isFollowingCreator ? 1.0 : 0.0;

        // 4. Factor de Novedad
        $noveltyFactor = $this->calculateNoveltyFactor($sampleVector->created_at);

        // Fórmula final ponderada
        $finalScore = ($similarityFactor * self::WEIGHT_SIMILARITY) +
            ($followingFactor * self::WEIGHT_FOLLOWING) +
            ($noveltyFactor * self::WEIGHT_NOVELTY);

        return (float) $finalScore;
    }

    /**
     * Calcula la similitud del coseno entre dos vectores.
     *
     * @param array $vec1 Vector numérico.
     * @param array $vec2 Vector numérico.
     * @return float Valor entre -1 y 1.
     */
    public function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = count($vec1);

        if ($count !== count($vec2) || $count === 0) {
            return 0.0; // Los vectores deben tener la misma dimensión y no ser vacíos.
        }

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += ($vec1[$i] ?? 0) * ($vec2[$i] ?? 0);
            $normA += ($vec1[$i] ?? 0) ** 2;
            $normB += ($vec2[$i] ?? 0) ** 2;
        }

        $magnitude = sqrt($normA) * sqrt($normB);

        return $magnitude == 0 ? 0.0 : $dotProduct / $magnitude;
    }

    /**
     * Calcula el factor de novedad basado en la fecha de creación del sample.
     * El bono decae linealmente durante los primeros 30 días.
     *
     * @param string|Carbon $creationDate
     * @return float Un valor entre 0 y 1.
     */
    private function calculateNoveltyFactor($creationDate): float
    {
        $createdAt = Carbon::parse($creationDate);
        $daysOld = $createdAt->diffInDays(Carbon::now());
        $noveltyPeriod = 30; // Días durante los cuales un sample se considera "nuevo".

        if ($daysOld < 0 || $daysOld > $noveltyPeriod) {
            return 0.0;
        }

        // Decaimiento lineal
        return 1.0 - ($daysOld / $noveltyPeriod);
    }

    /**
     * Calcula la penalización si el usuario ya ha interactuado con el sample de forma definitiva.
     *
     * @param int $sampleId
     * @param array $userDefinitiveInteractions
     * @return float
     */
    private function calculatePenaltyFactor(int $sampleId, array $userDefinitiveInteractions): float
    {
        // Usar un hash map (isset en un array invertido) es más rápido que in_array para listas grandes.
        $interactionsMap = array_flip($userDefinitiveInteractions);
        if (isset($interactionsMap[$sampleId])) {
            return self::PENALTY_DEFINITIVE_INTERACTION;
        }
        return 0.0;
    }
}