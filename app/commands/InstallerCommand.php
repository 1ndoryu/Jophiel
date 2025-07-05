<?php

namespace app\commands;

use Illuminate\Database\Capsule\Manager as DB;

class InstallerCommand
{
    public function install(): void
    {
        echo "Iniciando instalación del esquema de Jophiel...\n";
        try {
            // Drop objects in reverse order of dependency
            DB::connection()->statement('DROP TABLE IF EXISTS recommendation_cache CASCADE');
            DB::connection()->statement('DROP TABLE IF EXISTS user_feed_recommendations CASCADE');
            DB::connection()->statement('DROP TABLE IF EXISTS user_interactions CASCADE');
            DB::connection()->statement('DROP TABLE IF EXISTS user_taste_profiles CASCADE');
            DB::connection()->statement('DROP TABLE IF EXISTS sample_vectors CASCADE');
            DB::connection()->statement('DROP TABLE IF EXISTS user_follows CASCADE');
            DB::connection()->statement('DROP TABLE IF EXISTS users CASCADE');
            DB::connection()->statement('DROP TYPE IF EXISTS interaction_type CASCADE');

            $sql = $this->getInstallSql();
            DB::connection()->unprepared($sql);
            echo "¡Esquema de base de datos creado con éxito!\n";
        } catch (\Exception $e) {
            echo "Error durante la instalación: " . $e->getMessage() . "\n";
        }
    }

    public function reset(bool $force = false): void
    {
        if (!$force) {
            echo "ADVERTENCIA: Esta acción eliminará todos los datos de las tablas de Jophiel.\n";
            echo "Escriba 'si' para continuar: ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            if (trim(strtolower($line)) !== 'si') {
                echo "Reinicio cancelado.\n";
                return;
            }
            fclose($handle);
        }

        echo "Reiniciando tablas...\n";
        try {
            DB::statement('TRUNCATE TABLE user_interactions, user_taste_profiles, sample_vectors, user_feed_recommendations, recommendation_cache, user_follows RESTART IDENTITY');
            echo "¡Tablas reiniciadas con éxito!\n";
        } catch (\Exception $e) {
            echo "Error durante el reinicio: " . $e->getMessage() . "\n";
        }
    }

    private function getInstallSql(): string
    {
        return "
CREATE TYPE interaction_type AS ENUM ('like', 'dislike', 'play', 'skip', 'follow', 'comment');

CREATE TABLE sample_vectors (
    sample_id BIGINT PRIMARY KEY,
    creator_id BIGINT NOT NULL,
    vector JSONB NOT NULL,
    search_tsv TSVECTOR,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sample_vectors_vector_gin ON sample_vectors USING GIN (vector);
CREATE INDEX idx_sample_vectors_search_tsv_gin ON sample_vectors USING GIN (search_tsv);
COMMENT ON TABLE sample_vectors IS 'Almacena el vector numérico (ADN) de cada sample de audio y su índice de búsqueda textual.';

CREATE TABLE user_taste_profiles (
    user_id BIGINT PRIMARY KEY,
    taste_vector JSONB NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
COMMENT ON TABLE user_taste_profiles IS 'Almacena el vector de gustos agregado para cada usuario.';

CREATE TABLE user_interactions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    sample_id BIGINT NOT NULL,
    interaction_type interaction_type NOT NULL,
    weight REAL NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL
);
CREATE INDEX idx_user_interactions_user_id ON user_interactions(user_id);
CREATE INDEX idx_user_interactions_created_at ON user_interactions(created_at);
CREATE INDEX idx_user_interactions_unprocessed ON user_interactions(id) WHERE processed_at IS NULL;
COMMENT ON TABLE user_interactions IS 'Registro de todas las interacciones ponderadas de los usuarios con los samples.';

CREATE TABLE user_feed_recommendations (
    user_id BIGINT NOT NULL,
    sample_id BIGINT NOT NULL,
    score REAL NOT NULL,
    generated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, sample_id)
);
COMMENT ON TABLE user_feed_recommendations IS 'Tabla final con el top N de recomendaciones pre-calculadas para cada usuario.';

CREATE TABLE recommendation_cache (
    cache_key VARCHAR(255) PRIMARY KEY,
    recommended_ids JSONB NOT NULL,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL
);
CREATE INDEX idx_recommendation_cache_expires_at ON recommendation_cache(expires_at);
COMMENT ON TABLE recommendation_cache IS 'Caché para resultados costosos como \"samples similares\" o \"ideas para tableros\".';

CREATE TABLE user_follows (
    user_id BIGINT NOT NULL,
    followed_user_id BIGINT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, followed_user_id)
);
COMMENT ON TABLE user_follows IS 'Registra qué usuarios siguen a qué creadores.';

CREATE TABLE users (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
COMMENT ON TABLE users IS 'Tabla de usuarios (para propósitos de testing en Jophiel).';
";
    }
}