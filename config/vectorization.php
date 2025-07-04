<?php

/**
 * Configuración para el proceso de vectorización de Jophiel.
 * Define la estructura y las características que componen el vector de un sample.
 */
return [
    /**
     * Define la dimensión total del vector.
     * Es la suma de las dimensiones de todas las características.
     * BPM (1) + Genero (20) + Emocion (16) + Instrumentos (18) + Tipo (2) + TagsHash (128) = 185
     */
    'vector_dimension' => 65 + 128,

    /**
     * Características numéricas y su normalización.
     */
    'numerical_features' => [
        'bpm' => [
            'min' => 60,  // BPM mínimo esperado
            'max' => 200, // BPM máximo esperado
        ],
    ],

    /**
     * Vocabularios para características categóricas (multi-hot encoding).
     * El orden y la presencia de cada término son cruciales para la consistencia del vector.
     */
    'categorical_features' => [
        'genero' => [
            'ambient',
            'post-rock',
            'indie',
            'lofi',
            'techno',
            'house',
            'hip-hop',
            'trap',
            'drum-and-bass',
            'classical',
            'jazz',
            'chill',
            'witch-house',
            'hyperpop',
            'experimental',
            'cloud-rap',
            'rock',
            'phonk',
            'memphis',
            'pop',
            'metal',
            'reggaeton',
            'edm',
            'blues',
            'soul',
            'funk',
            'r&b',
            'folkloric',
            'regional',
        ],
        'emocion_es' => [
            'triste',
            'melancólico',
            'reflexivo',
            'calmado',
            'nostálgico',
            'feliz',
            'enérgico',
            'épico',
            'oscuro',
            'agresivo',
            'romántico',
            'relajado',
            'inspirador',
            'motivador',
            'terror',
            'sereno'
        ],
        'instrumentos' => [
            'guitar',
            'piano',
            'synth',
            'drums',
            'bass',
            'strings',
            'vocals',
            'percussion',
            'fx',
            'brass',
            'flute',
            'violin',
            'trumpet',
            'cello',
            'saxophone',
            'ukulele',
            'harp',
            'accordion', 
            'cowbell',
        ],
        'tipo' => [
            'loop',
            'one-shot'
        ],
        // 'tags' se omite intencionadamente aquí por su alta cardinalidad.
        // Se podría añadir en el futuro con una estrategia de embedding más avanzada (Fase 2).
    ],

    // Dimensión de hashing para los tags (Hashing Trick)
    'hash_dimension' => 128,
];
