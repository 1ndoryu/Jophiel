<?php

/**
 * Configuración principal del algoritmo de recomendación de Jophiel.
 *
 * Este archivo centraliza TODOS los valores que regulan el funcionamiento
 * del sistema de scoring y de reacción inmediata. La intención es que cualquier
 * ajuste de pesos o umbrales pueda hacerse sin tocar el código fuente.
 *
 * Convención general:
 *  - Todos los valores numéricos están normalizados en el rango [-1, 1] o
 *    representan pesos relativos.  Ajustar con mesura.
 *  - Los comentarios describen *por qué* existe cada parámetro y su efecto.
 *
 * NOTA:  Cualquier cambio importante debe ir acompañado de:
 *  1) Pruebas automatizadas que validen el impacto.
 *  2) Un registro de benchmarks en /storage/benchmarks para mantener trazabilidad
 *     del rendimiento.
 */
return [
    /* --------------------------------------------------------------------- */
    /* 1. Factores base de la fórmula de puntuación                          */
    /* --------------------------------------------------------------------- */

    /**
     * Peso relativo de cada componente en la fórmula:
     *   Score = (similarity * W1) + (following * W2) + (novelty * W3) + penalty
     */
    'score_weights' => [
        'similarity' => 1.0,   // Compatibilidad vectorial usuario-sample
        'following'  => 0.5,   // Bono si el usuario sigue al creador del sample
        'novelty'    => 0.2,   // Bono decreciente para contenido nuevo
    ],

    /**
     * Penalizaciones brutales destinadas a descartar contenido.  Se aplican
     * DESPUÉS de sumar los factores positivos.
     */
    'score_penalties' => [
        'already_interacted' => -0.3, // Oculta likes/dislikes previos
    ],

    /**
     * Configuración del factor de Novedad.
     * La bonificación decae exponencialmente según la antigüedad.
     */
    'novelty' => [
        'half_life_hours' => 48, // A las 48h el bono se reduce al 50 %
        'max_bonus'       => 1.0, // Bono máximo que se multiplica por W3
    ],

    /* Visibilidad tras interacción definitiva */
    'visibility_factor_definitive_interaction' => 0.3,

    /* --------------------------------------------------------------------- */
    /* 2. Peso de cada tipo de interacción                                  */
    /* --------------------------------------------------------------------- */

    /**
     * Estos pesos alimentan el cálculo del vector de gusto del usuario
     * (`user_taste_profile`).  Valores positivos acercan el vector del sample
     * al perfil del usuario; negativos lo alejan.
     */
    'interaction_weights' => [
        // Interacciones de alto valor
        'like'          =>  1.0,
        'share'         =>  0.8,
        'comment'       =>  0.8,
        'add_to_board'  =>  0.9,
        'follow_user'   =>  0.6,

        // Interacciones de valor medio/bajo
        'play'          =>  0.2,
        'skip'          => -0.3,

        // Acciones de reversión
        'unlike'        =>  0.0,
        'unfollow'      => -0.6,
    ],

    /* --------------------------------------------------------------------- */
    /* 3. Reacción Inmediata (Quick Update)                                  */
    /* --------------------------------------------------------------------- */

    'quick_reaction' => [
        /**
         * Eventos que provocan una actualización instantánea del feed.
         */
        'high_value_events' => [
            'like',
            'share',
            'comment',
            'add_to_board',
            'follow_user',
        ],

        /**
         * Umbral acumulativo para eventos de bajo valor antes de reaccionar.
         */
        'low_value_batch' => [
            'events'         => ['play', 'skip'],
            'threshold'      => 5,   // nº de eventos
            'within_minutes' => 10,  // ventana temporal
        ],
    ],

    /* --------------------------------------------------------------------- */
    /* 3.b Parámetros de Quick Update (Motor de reacción inmediata)          */
    /* --------------------------------------------------------------------- */

    /**
     * Control fino del motor `QuickUpdateService` que reacciona al instante
     * ante señales de alto valor (p.e. like, comment, follow).  Ajustar aquí
     * permite experimentar sin tocar el código PHP.
     */
    'quick_update_engine' => [
        // Nº de samples "parecidos" que se inyectan tras una interacción positiva
        'similar_samples_to_inject'  => 10,
        // Nº de samples del creador seguido que se inyectan tras un follow
        'followed_samples_to_inject' => 15,
        // Tasa de aprendizaje (alpha) al actualizar el vector de gustos online
        'taste_update_alpha'         => 0.1,
    ],

    /* --------------------------------------------------------------------- */
    /* 4. Parámetros del proceso Batch                                       */
    /* --------------------------------------------------------------------- */

    /**
     * El `RecommendationService` procesa grandes lotes de interacciones en
     * segundo plano para recalcular los feeds completos.  Estos valores
     * permiten escalar el proceso y ajustar la calidad/latencia.
     */
    'batch_processing' => [
        // Nº máx. de interacciones que se leen por ciclo de proceso
        'interaction_batch_size'      => 1000,
        // Rate de aprendizaje al recalcular el taste profile en batch
        'profile_update_learning_rate' => 0.05,
        // Umbral (> x) para considerar un índice "caliente" del taste vector
        'taste_vector_threshold'      => 0.1,
    ],

    /* --------------------------------------------------------------------- */
    /* 4. Parámetros globales de recomendación                               */
    /* --------------------------------------------------------------------- */

    'recommendations' => [
        // Nº máximo de recomendaciones que se guardan por usuario
        'feed_size'         => 100,
        // Nº de resultados para "samples similares"
        'similar_samples'   => 20,
        // Nº de resultados para "ideas para tablero"
        'ideas_for_board'   => 50,
    ],

    /* --------------------------------------------------------------------- */
    /* 5. Búsqueda de candidatos                                             */
    /* --------------------------------------------------------------------- */

    'candidate_search' => [
        // Límite superior de candidatos devueltos por la fase de pre-filtrado
        'max_candidates'    => 5000,
        // Métrica de distancia para la similitud (cosine, euclidean, etc.)
        'distance_metric'   => 'cosine',
    ],
]; 