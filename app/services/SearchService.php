<?php

namespace app\services;

use app\services\concerns\ProvidesUserData;
use app\model\UserTasteProfile;
use app\model\SampleVector;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Servicio para realizar búsqueda híbrida (relevancia textual + personalización).
 */
class SearchService
{
    use ProvidesUserData;

    private ScoreCalculationService $scoreCalc;
    private int $candidateLimit;
    private float $weightText;
    private float $weightPersonal;

    public function __construct()
    {
        $this->scoreCalc = new ScoreCalculationService();
        $this->candidateLimit  = (int) config('search.fts_candidate_limit', 500);
        $weights               = config('search.score_weights');
        $this->weightText      = (float) ($weights['text_relevance']  ?? 0.5);
        $this->weightPersonal  = (float) ($weights['personalization'] ?? 0.5);
    }

    /**
     * Ejecuta una búsqueda híbrida.
     *
     * @param string $term    Término de búsqueda ingresado por el usuario.
     * @param int    $userId  ID del usuario (para personalización).
     * @param int    $page    Página solicitada (1-indexed).
     * @param int    $perPage Elementos por página (1-100).
     * @return array  [ 'results' => array<['sample_id', 'score']>, 'total', 'page', 'per_page' ]
     */
    public function search(string $term, int $userId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, (int) $page);
        $perPage = max(1, min((int) $perPage, 100));

        $trimmedTerm = trim($term);
        if ($trimmedTerm === '') {
            return [
                'results'  => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $perPage,
            ];
        }

        // ----------------------------------------------
        // Paso 1: Buscar candidatos vía Full-Text Search
        // ----------------------------------------------
        $sql = "
            SELECT sample_id, creator_id, vector, created_at,
                   ts_rank(search_tsv, plainto_tsquery('simple', ?)) AS text_rank
            FROM sample_vectors
            WHERE search_tsv @@ plainto_tsquery('simple', ?)
            ORDER BY text_rank DESC
            LIMIT ?";

        $rows = DB::select($sql, [$trimmedTerm, $trimmedTerm, $this->candidateLimit]);

        if (empty($rows)) {
            return [
                'results'  => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $perPage,
            ];
        }

        // ----------------------------------------------
        // Paso 2: Preparar datos del usuario
        // ----------------------------------------------
        $profile          = UserTasteProfile::find($userId);
        $dimension        = (int) config('vectorization.vector_dimension');
        $userTasteVector  = $profile ? $profile->taste_vector : array_fill(0, $dimension, 0.0);

        $definitiveInts   = $this->getUserDefinitiveInteractions($userId);
        $followedCreators = $this->getFollowedCreatorsForUsers([$userId])
            ->get($userId, collect())
            ->flip(); // Hash-map para lookup O(1)

        // ----------------------------------------------
        // Paso 3: Calcular puntuaciones híbridas
        // ----------------------------------------------
        $scored = [];
        foreach ($rows as $row) {
            $sampleVector            = new SampleVector();
            $sampleVector->sample_id = (int) $row->sample_id;
            $sampleVector->creator_id = (int) $row->creator_id;
            $sampleVector->vector    = is_array($row->vector) ? $row->vector : json_decode($row->vector, true);
            $sampleVector->created_at = $row->created_at;

            $personalScore = $this->scoreCalc->calculateFinalScore(
                $userTasteVector,
                $sampleVector,
                $definitiveInts,
                isset($followedCreators[$row->creator_id])
            );

            $finalScore = ($row->text_rank * $this->weightText) + ($personalScore * $this->weightPersonal);

            $scored[] = [
                'sample_id' => (int) $row->sample_id,
                'score'     => $finalScore,
            ];
        }

        // ----------------------------------------------
        // Paso 4: Ordenar y paginar resultados
        // ----------------------------------------------
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $total  = count($scored);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($scored, $offset, $perPage);

        return [
            'results'  => $paged,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
} 