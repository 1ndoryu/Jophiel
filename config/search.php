<?php
return [
    // Pesos de campos de texto en la generación del TSVECTOR
    'weights' => [
        'title'        => 'A', // Máxima relevancia
        'tags'         => 'B',
        'genero'       => 'B',
        'instrumentos' => 'B',
        'descripcion'  => 'C', // Menor relevancia
    ],

    // Límite de candidatos devueltos por la consulta FTS inicial
    'fts_candidate_limit' => 500,

    // Pesos de la fórmula híbrida (relevancia vs. personalización)
    'score_weights' => [
        'text_relevance'   => 0.5,
        'personalization'  => 0.5,
    ],
]; 