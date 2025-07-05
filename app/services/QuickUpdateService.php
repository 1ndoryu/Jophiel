<?php

namespace app\services;

use app\model\SampleVector;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use app\model\UserFeedRecommendation;
use app\model\UserFollow;
use app\services\concerns\ProvidesUserData;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;
use app\helper\LogHelper;

class QuickUpdateService
{
    use ProvidesUserData;

    private ScoreCalculationService $scoreService;
    private int $similarSamplesToInject;
    private int $followedSamplesToInject;
    private float $tasteUpdateAlpha;
    private int $feedSize;

    /**
     * Canal de log para este servicio.
     */
    private string $logChannel = 'quick-update';

    /**
     * Helper para mensajes DEBUG que antes salían por consola.
     */
    private function debug(string $message, array $context = []): void
    {
        LogHelper::debug($this->logChannel, $message, $context);
    }

    public function __construct()
    {
        $this->scoreService         = new ScoreCalculationService();
        $engineConfig               = config('recommendation.quick_update_engine');

        $this->similarSamplesToInject  = (int) ($engineConfig['similar_samples_to_inject'] ?? 10);
        $this->followedSamplesToInject = (int) ($engineConfig['followed_samples_to_inject'] ?? 15);
        $this->tasteUpdateAlpha        = (float) ($engineConfig['taste_update_alpha'] ?? 0.1);

        $this->feedSize = (int) config('recommendation.recommendations.feed_size', 200);
    }

    public function handleLike(int $userId, int $sampleId): void
    {
        $this->debug('Procesando LIKE', [
            'user_id'   => $userId,
            'sample_id' => $sampleId,
        ]);
        $this->runQuickUpdateForPositiveSignal($userId, $sampleId, 'like');
    }

    public function handleComment(int $userId, int $sampleId): void
    {
        $this->runQuickUpdateForPositiveSignal($userId, $sampleId, 'comment');
    }

    private function runQuickUpdateForPositiveSignal(int $userId, int $sampleId, string $interactionType): void
    {
        $this->debug('QuickUpdate iniciado', [
            'interaction_type' => $interactionType,
            'user_id'         => $userId,
            'sample_id'       => $sampleId,
        ]);
        // Aseguramos que no se creen duplicados de interacciones definitivas.
        // Si ya existe un "like" (o cualquier otra interacción positiva definitiva que queramos tratar así),
        // simplemente actualizamos el peso; en caso contrario se inserta un nuevo registro.
        UserInteraction::updateOrCreate([
            'user_id'          => $userId,
            'sample_id'        => $sampleId,
            'interaction_type' => $interactionType,
        ], [
            'weight' => 1.0,
        ]);
        $this->debug('Interacción registrada', [
            'user_id'          => $userId,
            'sample_id'        => $sampleId,
            'interaction_type' => $interactionType,
        ]);

        $userProfile = UserTasteProfile::find($userId);
        $interactedSample = SampleVector::find($sampleId);

        // Si no existe el perfil de gustos, lo creamos con un vector neutro.
        if (!$userProfile) {
            $this->debug('Perfil de gustos no encontrado; creando perfil por defecto.', ['user_id' => $userId]);
            try {
                $dimension   = config('vectorization.vector_dimension', 35);
                $userProfile = UserTasteProfile::create([
                    'user_id'      => $userId,
                    'taste_vector' => array_fill(0, $dimension, 0.0),
                ]);
            } catch (\Throwable $e) {
                LogHelper::error($this->logChannel, 'No se pudo crear perfil de gustos por defecto.', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
                $userProfile = null;
            }
        }

        // Verificación final: si aún no tenemos perfil, abortamos.
        if (!$userProfile) {
            $this->debug('No se pudo generar/obtener el perfil de usuario, abortando QuickUpdate.', [
                'user_id' => $userId,
            ]);
            return;
        }

        // Si el vector del sample aún no está disponible (por ejemplo, la vectorización no ha terminado),
        // no actualizamos el taste profile ni calculamos candidatos, pero SÍ inyectamos el propio sample
        // al feed para que el usuario vea reflejada su interacción de inmediato.
        if (!$interactedSample) {
            $this->debug('Vector del sample no encontrado; inyectando el sample directamente y finalizando QuickUpdate.', [
                'sample_id' => $sampleId,
            ]);

            $this->injectIntoFeed($userId, [[
                'user_id'      => $userId,
                'sample_id'    => $sampleId,
                'score'        => 1.0,
                'generated_at' => Carbon::now()->toDateTimeString(),
            ]]);

            return; // Salimos porque no podemos continuar sin el vector
        }

        // Aseguramos que el vector de gustos del usuario sea un array. Puede venir como JSON string desde la BD.
        $userTasteVector = is_array($userProfile->taste_vector)
            ? $userProfile->taste_vector
            : json_decode($userProfile->taste_vector, true) ?? [];

        // También nos aseguramos de que el vector del sample de la interacción sea array por coherencia.
        if (!is_array($interactedSample->vector)) {
            $interactedSample->vector = json_decode($interactedSample->vector, true) ?? [];
        }

        // === NUEVO: Actualizar el perfil de gustos ===
        $oldTasteVector = $userTasteVector;
        $dimension = count($interactedSample->vector);
        if ($dimension === 0) {
            $this->debug('Vector del sample vacío; se omite actualización del taste profile');
        } else {
            // Garantizar que ambos vectores tengan la misma dimensión rellenando con ceros si falta longitud.
            for ($i = 0; $i < $dimension; $i++) {
                $oldVal    = $userTasteVector[$i] ?? 0.0;
                $sampleVal = $interactedSample->vector[$i] ?? 0.0;
                $userTasteVector[$i] = (1 - $this->tasteUpdateAlpha) * $oldVal + $this->tasteUpdateAlpha * $sampleVal;
            }
            // Persistir cambios
            $userProfile->taste_vector = $userTasteVector;
            $userProfile->save();

            // Log antes y después (solo las primeras 10 posiciones para no inundar)
            $this->debug('Taste profile actualizado', [
                'user_id' => $userId,
                'first_10_before' => array_slice($oldTasteVector, 0, 10),
                'first_10_after'  => array_slice($userTasteVector, 0, 10),
            ]);
        }

        $candidates = $this->findSimilarSamples($interactedSample);
        $this->debug('Candidatos similares encontrados', ['count' => $candidates->count()]);

        if ($candidates->isEmpty()) {
            $this->injectIntoFeed($userId, [[
                'user_id' => $userId,
                'sample_id' => $sampleId,
                'score' => 1.0,
                'generated_at' => Carbon::now()->toDateTimeString(),
            ]]);
            return;
        }

        $definitiveInteractions = $this->getUserDefinitiveInteractions($userId);
        $followedCreators = $this->getFollowedCreatorsForUsers([$userId])->get($userId, collect())->flip();
        $newRecommendations = [];

        // --- INICIO DE DEPURACIÓN VERBOSA ---
        $this->debug('Comienza foreach de candidatos');
        foreach ($candidates as $key => $candidate) {
            $this->debug('Procesando candidato', ['loop_key' => $key]);

            // 1. VERIFICACIÓN DE TIPO
            if (!is_object($candidate) || !$candidate instanceof \app\model\SampleVector) {
                $this->debug('Candidato inválido', ['type' => gettype($candidate)]);
                continue;
            }

            $this->debug('ID del candidato', ['candidate_id' => $candidate->sample_id]);

            // 2. VERIFICACIÓN DEL VECTOR
            if (!isset($candidate->vector)) {
                $this->debug('Vector del candidato indefinido', ['candidate_id' => $candidate->sample_id]);
                continue;
            }
            if (!is_array($candidate->vector)) {
                $this->debug('Vector del candidato no es array', ['type' => gettype($candidate->vector)]);
                continue;
            }

            if ($candidate->sample_id == $sampleId) {
                $this->debug('Saltando candidato igual al sample');
                continue;
            }

            $this->debug('Llamando a calculateFinalScore');
            $score = $this->scoreService->calculateFinalScore(
                $userTasteVector,
                $candidate,
                $definitiveInteractions,
                $followedCreators->has($candidate->creator_id)
            );
            $this->debug('Score calculado', ['candidate_id' => $candidate->sample_id, 'score' => $score]);

            if ($score >= 0) {
                $this->debug('Score positivo, añadiendo a recomendaciones');
                $newRecommendations[] = ['user_id' => $userId, 'sample_id' => $candidate->sample_id, 'score' => $score, 'generated_at' => Carbon::now()->toDateTimeString()];
            } else {
                $this->debug('Score negativo, omitiendo');
            }
        }
        $this->debug('Foreach completado');
        // --- FIN DE DEPURACIÓN VERBOSA ---

        $this->debug('Nuevas recomendaciones generadas', ['count' => count($newRecommendations)]);

        if (empty($newRecommendations)) {
            $this->debug('No se generaron nuevas recomendaciones, retornando');
            return;
        }

        $this->injectIntoFeed($userId, $newRecommendations);
    }

    public function handleFollow(int $followerId, int $followedUserId): void
    {
        // El código de este método no se ha modificado para mantener el foco en el primer error (like)
        $this->debug('QuickUpdate follow', ['follower_id' => $followerId, 'followed_user_id' => $followedUserId]);
        UserFollow::updateOrCreate(['user_id' => $followerId, 'followed_user_id' => $followedUserId]);
        $followerProfile = UserTasteProfile::find($followerId);
        if (!$followerProfile) return;
        $followerTasteVector = is_array($followerProfile->taste_vector)
            ? $followerProfile->taste_vector
            : json_decode($followerProfile->taste_vector, true) ?? [];
        $candidates = SampleVector::where('creator_id', $followedUserId)->latest('created_at')->limit($this->followedSamplesToInject * 2)->get();
        $this->debug('Samples del seguido encontrados', ['count' => $candidates->count()]);
        if ($candidates->isEmpty()) return;
        $definitiveInteractions = $this->getUserDefinitiveInteractions($followerId);
        $newRecommendations = [];
        foreach ($candidates as $candidate) {
            // Aseguramos que el vector del candidato sea array
            if (!is_array($candidate->vector)) {
                $candidate->vector = json_decode($candidate->vector, true) ?? [];
            }

            $score = $this->scoreService->calculateFinalScore($followerTasteVector, $candidate, $definitiveInteractions, true);
            if ($score > 0) {
                $newRecommendations[] = ['user_id' => $followerId, 'sample_id' => $candidate->sample_id, 'score' => $score, 'generated_at' => Carbon::now()->toDateTimeString()];
            }
        }
        if (empty($newRecommendations)) {
            $latestSample = $candidates->first();
            if ($latestSample && !in_array($latestSample->sample_id, $definitiveInteractions)) {
                $newRecommendations[] = ['user_id' => $followerId, 'sample_id' => $latestSample->sample_id, 'score' => 0.5, 'generated_at' => Carbon::now()->toDateTimeString()];
            }
        }
        $this->injectIntoFeed($followerId, array_slice($newRecommendations, 0, $this->followedSamplesToInject));
    }

    private function injectIntoFeed(int $userId, array $newRecommendations): void
    {
        $this->debug('injectIntoFeed: inicio', ['user_id' => $userId, 'count_new' => count($newRecommendations)]);
        if (empty($newRecommendations)) return;

        // Nuevo: capturar conteo actual del feed ANTES de modificarlo
        $feedCountBefore = UserFeedRecommendation::where('user_id', $userId)->count();

        try {
            DB::transaction(function () use ($userId, $newRecommendations) {
                $newSampleIds = array_column($newRecommendations, 'sample_id');
                $currentFeed = UserFeedRecommendation::where('user_id', $userId)->whereNotIn('sample_id', $newSampleIds)->orderBy('score', 'desc')->get()->toArray();
                $combinedFeed = array_merge($newRecommendations, $currentFeed);
                usort($combinedFeed, fn($a, $b) => $b['score'] <=> $a['score']);

                $this->debug('IDs insertados', ['sample_ids' => $newSampleIds]);

                $finalFeed = array_slice($combinedFeed, 0, $this->feedSize);
                $this->debug('Feed combinado', ['elements' => count($finalFeed)]);
                UserFeedRecommendation::where('user_id', $userId)->delete();
                if (!empty($finalFeed)) {
                    $inserted = UserFeedRecommendation::insert($finalFeed);
                    $this->debug('IDs finalFeed', ['sample_ids' => array_column($finalFeed, 'sample_id')]);
                    $this->debug('Resultado insert', ['success' => (bool)$inserted]);
                }
            });
            $countAfter = UserFeedRecommendation::where('user_id', $userId)->count();

            // Nuevo log con el diff inmediato
            $this->debug('Feed modificado', [
                'feed_count_before' => $feedCountBefore,
                'feed_count_after'  => $countAfter,
                'delta'             => $countAfter - $feedCountBefore,
            ]);
            $this->debug('Conteo final post-transacción', ['count' => $countAfter]);
        } catch (\Throwable $e) {
            LogHelper::error($this->logChannel, 'Excepción en injectIntoFeed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    public function handleUnlike(int $userId, int $sampleId): void
    {
        $this->debug('Procesando UNLIKE (remover like)', [
            'user_id'   => $userId,
            'sample_id' => $sampleId,
        ]);

        // 1) Eliminamos cualquier registro existente de 'like' para este usuario y sample.
        $deletedInteractions = UserInteraction::where('user_id', $userId)
            ->where('sample_id', $sampleId)
            ->where('interaction_type', 'like')
            ->delete();

        $this->debug('Like eliminado de user_interactions', [
            'deleted_rows' => $deletedInteractions,
            'user_id'      => $userId,
            'sample_id'    => $sampleId,
        ]);

        // 2) Revertir la actualización realizada al taste_vector en el último LIKE
        $userProfile = UserTasteProfile::find($userId);
        $sampleVectorModel = SampleVector::find($sampleId);

        if ($userProfile && $sampleVectorModel && $this->tasteUpdateAlpha > 0 && $this->tasteUpdateAlpha < 1) {
            $userTasteVector = is_array($userProfile->taste_vector)
                ? $userProfile->taste_vector
                : json_decode($userProfile->taste_vector, true) ?? [];

            $sampleVector = is_array($sampleVectorModel->vector)
                ? $sampleVectorModel->vector
                : json_decode($sampleVectorModel->vector, true) ?? [];

            $dimension = min(count($userTasteVector), count($sampleVector));
            if ($dimension > 0) {
                for ($i = 0; $i < $dimension; $i++) {
                    $currentVal = $userTasteVector[$i] ?? 0.0;
                    $sampleVal  = $sampleVector[$i] ?? 0.0;
                    // Solo revertimos si este índice fue afectado por el LIKE original (sampleVal != 0).
                    if (abs($sampleVal) > 1e-9) {
                        // Fórmula inversa del update aplicado en handleLike:
                        // new = (1-alpha)*old + alpha*sample  => old = (new - alpha*sample)/(1-alpha)
                        $reverted = ($currentVal - $this->tasteUpdateAlpha * $sampleVal) / (1 - $this->tasteUpdateAlpha);
                        $userTasteVector[$i] = $reverted;
                    }
                }

                $userProfile->taste_vector = $userTasteVector;
                $userProfile->save();

                $this->debug('Taste profile revertido tras UNLIKE', [
                    'user_id' => $userId,
                    'first_10_after_revert' => array_slice($userTasteVector, 0, 10),
                ]);
            }
        } else {
            $this->debug('No se pudo revertir el taste profile: datos insuficientes', [
                'has_user_profile' => (bool)$userProfile,
                'has_sample_vector' => (bool)$sampleVectorModel,
            ]);
        }

        // 3) Eliminamos temporalmente el sample del feed actual del usuario para reflejar el cambio inmediato.
        $deleted = UserFeedRecommendation::where('user_id', $userId)
            ->where('sample_id', $sampleId)
            ->delete();

        $this->debug('Sample eliminado temporalmente del feed', [
            'deleted_rows' => $deleted,
        ]);
    }

    public function handleUnfollow(int $followerId, int $unfollowedUserId): void
    {
        UserFollow::where('user_id', $followerId)->where('followed_user_id', $unfollowedUserId)->delete();
        $sampleIdsToRemove = SampleVector::where('creator_id', $unfollowedUserId)->pluck('sample_id');
        if ($sampleIdsToRemove->isEmpty()) {
            return;
        }
        UserFeedRecommendation::where('user_id', $followerId)->whereIn('sample_id', $sampleIdsToRemove)->delete();
    }

    private function findSimilarSamples(SampleVector $sample): Collection
    {
        $vectorData = is_array($sample->vector) ? $sample->vector : json_decode($sample->vector, true);
        $hotIndices = array_keys(array_filter($vectorData, fn($val) => $val > 0));
        if (empty($hotIndices)) {
            return SampleVector::inRandomOrder()->limit($this->similarSamplesToInject)->get();
        }
        $query = SampleVector::query()->where('sample_id', '!=', $sample->sample_id);
        $query->where(function ($q) use ($hotIndices) {
            foreach ($hotIndices as $index) {
                $q->orWhereRaw("CAST (vector->>" . (int)$index . " AS numeric) > 0");
            }
        });
        $similar = $query->inRandomOrder()->limit($this->similarSamplesToInject)->get();

        // Fallback: si no se encontraron suficientes similares, rellenamos con aleatorios
        if ($similar->count() < $this->similarSamplesToInject) {
            $missing = $this->similarSamplesToInject - $similar->count();
            $additional = SampleVector::where('sample_id', '!=', $sample->sample_id)
                ->whereNotIn('sample_id', $similar->pluck('sample_id'))
                ->inRandomOrder()
                ->limit($missing)
                ->get();
            $similar = $similar->concat($additional);
        }

        return $similar;
    }
}
