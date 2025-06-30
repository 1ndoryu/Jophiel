#!/usr/bin/env php
<?php
// Script para generar el SQL de instalación de Jophiel.
// Uso: php database/install.php > install.sql

echo "-- Esquema de base de datos para el proyecto Jophiel\n";
echo "-- Generado en: " . date('Y-m-d H:i:s') . "\n\n";

// --- Tipo Enum para Interacciones ---
echo "CREATE TYPE interaction_type AS ENUM ('like', 'dislike', 'play', 'skip', 'follow');\n\n";

// --- Tabla: sample_vectors ---
// Almacena el ADN numérico de cada sample.
echo "-- Tabla para los vectores de los samples\n";
echo "CREATE TABLE sample_vectors (
    sample_id BIGINT PRIMARY KEY,
    vector TEXT NOT NULL, -- Para pgvector usar: vector(N), donde N es la dimensión. Usamos TEXT para compatibilidad inicial.
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);\n";
echo "COMMENT ON TABLE sample_vectors IS 'Almacena el vector numérico (ADN) de cada sample de audio.';\n\n";

// --- Tabla: user_taste_profiles ---
// Almacena el vector de gustos agregado de cada usuario.
echo "-- Tabla para los perfiles de gusto de los usuarios\n";
echo "CREATE TABLE user_taste_profiles (
    user_id BIGINT PRIMARY KEY,
    taste_vector TEXT NOT NULL, -- Para pgvector usar: vector(N). Usamos TEXT para compatibilidad inicial.
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);\n";
echo "COMMENT ON TABLE user_taste_profiles IS 'Almacena el vector de gustos agregado para cada usuario.';\n\n";

// --- Tabla: user_interactions ---
// Registro de todas las interacciones que alimentan el sistema.
echo "-- Tabla para el registro de interacciones de usuarios\n";
echo "CREATE TABLE user_interactions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    sample_id BIGINT NOT NULL,
    interaction_type interaction_type NOT NULL,
    weight REAL NOT NULL, -- Ponderación de la interacción
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);\n";
echo "CREATE INDEX idx_user_interactions_user_id ON user_interactions(user_id);\n";
echo "CREATE INDEX idx_user_interactions_created_at ON user_interactions(created_at);\n";
echo "COMMENT ON TABLE user_interactions IS 'Registro de todas las interacciones ponderadas de los usuarios con los samples.';\n\n";

// --- Tabla: user_feed_recommendations ---
// Almacena los resultados pre-calculados para una entrega rápida.
echo "-- Tabla para los resultados de recomendaciones pre-calculados\n";
echo "CREATE TABLE user_feed_recommendations (
    user_id BIGINT NOT NULL,
    sample_id BIGINT NOT NULL,
    score REAL NOT NULL,
    generated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, sample_id)
);\n";
echo "COMMENT ON TABLE user_feed_recommendations IS 'Tabla final con el top N de recomendaciones pre-calculadas para cada usuario.';\n\n";

// --- Tabla: recommendation_cache ---
// Caché para cálculos bajo demanda como "samples similares".
echo "-- Tabla de caché para recomendaciones bajo demanda\n";
echo "CREATE TABLE recommendation_cache (
    cache_key VARCHAR(255) PRIMARY KEY,
    recommended_ids JSONB NOT NULL,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL
);\n";
echo "CREATE INDEX idx_recommendation_cache_expires_at ON recommendation_cache(expires_at);\n";
echo "COMMENT ON TABLE recommendation_cache IS 'Caché para resultados costosos como \"samples similares\" o \"ideas para tableros\".';\n\n";

echo "-- Fin del script de instalación.\n";
