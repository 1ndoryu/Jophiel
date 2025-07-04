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
     * Parámetros cargados desde config/recommendation.php
     */
    private array $scoreWeights;
    private float $visibilityFactor;
    private array $noveltyConfig;

    // Constante preservada para compatibilidad con otros servicios
    public const PENALTY_DEFINITIVE_INTERACTION = -1000.0;

    public function __construct()
    {
        $this->scoreWeights    = config('recommendation.score_weights');
        $this->visibilityFactor = (float) config('recommendation.visibility_factor_definitive_interaction', 0.3);
        $this->noveltyConfig   = config('recommendation.novelty');
    }

    private string $logChannel = 'score-calculation';

    /**
     * Helper rápido para DEBUG.
     */
    private function debug(string $message, array $context = []): void
    {
        \app\helper\LogHelper::debug($this->logChannel, $message, $context);
    }

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
        // 1. ¿El usuario ya ha interactuado de forma definitiva con este sample?
        $hasDefinitiveInteraction = $this->hasDefinitiveInteraction($sampleVector->sample_id, $userDefinitiveInteractions);

        // 2. Factor de Similitud (Coseno)
        $similarityFactor = $this->calculateCosineSimilarity(
            $userTasteVector,
            $sampleVector->vector
        );

        // 3. Factor de Seguimiento
        $followingFactor = $isFollowingCreator ? 1.0 : 0.0;

        // 4. Factor de Novedad
        $noveltyFactor = $this->calculateNoveltyFactor($sampleVector->created_at);

        // Registro de los factores antes de ponderar
        $this->debug('Factores calculados', [
            'sample_id'                => $sampleVector->sample_id,
            'similarityFactor'         => $similarityFactor,
            'followingFactor'          => $followingFactor,
            'noveltyFactor'            => $noveltyFactor,
            'hasDefinitiveInteraction' => $hasDefinitiveInteraction,
        ]);

        // Fórmula final ponderada
        $finalScore = ($similarityFactor * ($this->scoreWeights['similarity'] ?? 1)) +
            ($followingFactor * ($this->scoreWeights['following'] ?? 0.5)) +
            ($noveltyFactor * ($this->scoreWeights['novelty'] ?? 0.2));

        // 5. Ajuste de visibilidad si ya hubo interacción definitiva
        if ($hasDefinitiveInteraction) {
            $finalScore *= $this->visibilityFactor;
            $this->debug('Visibilidad reducida por interacción definitiva', [
                'sample_id'      => $sampleVector->sample_id,
                'factor'         => $this->visibilityFactor,
                'adjustedScore'  => $finalScore,
            ]);
        }

        $this->debug('ScoreFinal calculado', [
            'sample_id'  => $sampleVector->sample_id,
            'finalScore' => $finalScore,
        ]);

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
        $hoursOld = $createdAt->diffInHours(Carbon::now());

        if ($hoursOld < 0) {
            return 0.0;
        }

        // Configuración dinámica
        $halfLifeHours = (int) ($this->noveltyConfig['half_life_hours'] ?? 48);
        $maxBonus      = (float) ($this->noveltyConfig['max_bonus'] ?? 1.0);

        // Decaimiento exponencial: bonus = max_bonus * 0.5^(t/half_life)
        $decay = pow(0.5, $hoursOld / $halfLifeHours);
        return $maxBonus * $decay;
    }

    /**
     * Determina si el usuario ha interactuado de forma definitiva con el sample.
     */
    private function hasDefinitiveInteraction(int $sampleId, array $userDefinitiveInteractions): bool
    {
        // Usar un hash map (isset en un array invertido) es más rápido que in_array para listas grandes.
        $interactionsMap = array_flip($userDefinitiveInteractions);
        return isset($interactionsMap[$sampleId]);
    }
}