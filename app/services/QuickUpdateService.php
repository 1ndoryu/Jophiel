<?php

namespace app\services;

use app\helper\LogHelper;
use app\model\SampleVector;
use app\model\UserInteraction;
use app\model\UserTasteProfile;
use app\model\UserFeedRecommendation;
use app\model\UserFollow;
use app\services\concerns\ProvidesUserData;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;

/**
* Servicio para manejar la "Reacción Inmediata" del feed ante eventos de alto valor.
*/
class QuickUpdateService
{
  use ProvidesUserData;

  private ScoreCalculationService $scoreService;
  private const SIMILAR_SAMPLES_TO_INJECT = 10;
  private const FOLLOWED_SAMPLES_TO_INJECT = 15;
  private const FEED_SIZE = 200; // Mantener consistente con RecommendationService

  public function __construct()
  {
    $this->scoreService = new ScoreCalculationService();
  }

  /**
  * Maneja la actualización en tiempo real del feed de un usuario tras un 'like'.
  *
  * @param int $userId
  * @param int $sampleId
  * @return void
  */
  public function handleLike(int $userId, int $sampleId): void
  {
    $this->runQuickUpdateForPositiveSignal($userId, $sampleId, 'like');
  }

  /**
  * Maneja la actualización en tiempo real del feed de un usuario tras un 'comment'.
  *
  * @param int $userId
  * @param int $sampleId
  * @return void
  */
  public function handleComment(int $userId, int $sampleId): void
  {
    $this->runQuickUpdateForPositiveSignal($userId, $sampleId, 'comment');
  }

  /**
  * Lógica genérica para una señal de interacción positiva (like, comment, etc.).
  * Inyecta contenido similar en el feed del usuario.
  *
  * @param int $userId
  * @param int $sampleId
  * @param string $interactionType
  * @return void
  */
  private function runQuickUpdateForPositiveSignal(int $userId, int $sampleId, string $interactionType): void
  {
    LogHelper::info('quick-update', "Iniciando actualización rápida para {$interactionType}.", ['user_id' => $userId, 'sample_id' => $sampleId]);

    // 1. Registrar la interacción (aún será procesada por el batch principal).
    UserInteraction::create([
      'user_id' => $userId,
      'sample_id' => $sampleId,
      'interaction_type' => $interactionType,
      'weight' => 1.0, // Peso para interacciones positivas fuertes
    ]);

    // 2. Obtener datos necesarios.
    $userProfile = UserTasteProfile::find($userId);
    $interactedSample = SampleVector::find($sampleId);

    if (!$userProfile || !$interactedSample) {
      LogHelper::warning('quick-update', 'No se pudo encontrar el perfil de usuario o el sample para la actualización rápida.', ['user_id' => $userId, 'sample_id' => $sampleId]);
      return;
    }

    // 3. Encontrar samples candidatos similares al que se le dio 'like'.
    $candidates = $this->findSimilarSamples($interactedSample);
    LogHelper::info('quick-update', 'Búsqueda de similares finalizada.', [
      'user_id' => $userId,
      'sample_id' => $sampleId,
      'found_candidates' => $candidates->count()
    ]);

    if ($candidates->isEmpty()) {
      LogHelper::info('quick-update', 'No se encontraron candidatos similares para inyectar.', ['sample_id' => $sampleId]);
      return;
    }

    // 4. Calcular el score para los nuevos candidatos.
    $definitiveInteractions = $this->getUserDefinitiveInteractions($userId);
    $followedCreators = $this->getFollowedCreatorsForUsers([$userId])->get($userId, collect())->flip();

    $newRecommendations = [];
    foreach ($candidates as $candidate) {
      if ($candidate->sample_id == $sampleId || isset($definitiveInteractions[$candidate->sample_id])) {
        continue;
      }

      $score = $this->scoreService->calculateFinalScore($userProfile->taste_vector, $candidate, $definitiveInteractions, $followedCreators->has($candidate->creator_id));

      if ($score > 0) { // Umbral simple para considerar una recomendación.
        $newRecommendations[] = [
          'user_id' => $userId,
          'sample_id' => $candidate->sample_id,
          'score' => $score,
          'generated_at' => now()
        ];
      }
    }

    if (empty($newRecommendations)) {
      LogHelper::info('quick-update', 'Ningún candidato similar generó un score positivo.', ['user_id' => $userId]);
      return;
    }

    // 5. Inyectar las nuevas recomendaciones en el feed del usuario.
    $this->injectIntoFeed($userId, $newRecommendations);
    LogHelper::info('quick-update', "Actualización rápida para {$interactionType} completada.", ['user_id' => $userId, 'injected_count' => count($newRecommendations)]);
  }

  /**
  * Maneja la actualización en tiempo real cuando un usuario sigue a otro.
  *
  * @param int $followerId El ID del usuario que sigue.
  * @param int $followedUserId El ID del usuario que es seguido.
  * @return void
  */
  public function handleFollow(int $followerId, int $followedUserId): void
  {
    LogHelper::info('quick-update', 'Iniciando actualización rápida para follow.', ['follower_id' => $followerId, 'followed_id' => $followedUserId]);

    // 1. Persistir la relación de seguimiento.
    UserFollow::updateOrCreate(
      ['user_id' => $followerId, 'followed_user_id' => $followedUserId]
    );

    // 2. Obtener datos del seguidor y los samples del seguido.
    $followerProfile = UserTasteProfile::find($followerId);
    if (!$followerProfile) {
      LogHelper::warning('quick-update', 'No se pudo encontrar el perfil del seguidor.', ['follower_id' => $followerId]);
      return;
    }

    // 3. Encontrar samples candidatos del creador recién seguido.
    $candidates = SampleVector::where('creator_id', $followedUserId)
      ->latest('created_at')
      ->limit(self::FOLLOWED_SAMPLES_TO_INJECT * 2)
      ->get();

    if ($candidates->isEmpty()) {
      LogHelper::info('quick-update', 'El creador seguido no tiene samples para inyectar.', ['followed_id' => $followedUserId]);
      return;
    }

    // 4. Calcular el score para los nuevos candidatos.
    $definitiveInteractions = $this->getUserDefinitiveInteractions($followerId);
    $followedCreators = $this->getFollowedCreatorsForUsers([$followerId])->get($followerId, collect())->flip();

    $newRecommendations = [];
    foreach ($candidates as $candidate) {
      if (isset($definitiveInteractions[$candidate->sample_id])) {
        continue;
      }

      // Al ser un follow, sabemos que el creador es seguido.
      $score = $this->scoreService->calculateFinalScore($followerProfile->taste_vector, $candidate, $definitiveInteractions, true);

      if ($score > 0) {
        $newRecommendations[] = [
          'user_id' => $followerId,
          'sample_id' => $candidate->sample_id,
          'score' => $score,
          'generated_at' => now()
        ];
      }
    }

    if (empty($newRecommendations)) {
      LogHelper::info('quick-update', 'Ningún sample del creador seguido generó un score positivo.', ['follower_id' => $followerId, 'followed_id' => $followedUserId]);
      return;
    }

    // 5. Inyectar las nuevas recomendaciones en el feed.
    $this->injectIntoFeed($followerId, array_slice($newRecommendations, 0, self::FOLLOWED_SAMPLES_TO_INJECT));
    LogHelper::info('quick-update', 'Actualización rápida para follow completada.', ['follower_id' => $followerId, 'injected_count' => count($newRecommendations)]);
  }


  /**
  * Busca samples con un vector similar al proporcionado, usando la misma lógica
  * de pre-filtrado por GIN index que el proceso batch.
  *
  * @param SampleVector $sample
  * @return Collection
  */
  private function findSimilarSamples(SampleVector $sample): Collection
  {
    $hotIndices = array_keys(array_filter($sample->vector, fn($val) => $val > 0.9));

    if (empty($hotIndices)) {
      return collect();
    }

    $query = SampleVector::query()->where('sample_id', '!=', $sample->sample_id);

    $query->where(function ($q) use ($hotIndices) {
      foreach ($hotIndices as $index) {
        $q->orWhereRaw("vector->>" . (int)$index . " = '1'");
      }
    });

    return $query->inRandomOrder()->limit(self::SIMILAR_SAMPLES_TO_INJECT * 2)->get();
  }

  /**
  * Inyecta nuevas recomendaciones al inicio del feed, manteniendo el tamaño total.
  *
  * @param int $userId
  * @param array $newRecommendations
  * @return void
  */
  private function injectIntoFeed(int $userId, array $newRecommendations): void
  {
    DB::transaction(function () use ($userId, $newRecommendations) {
      $newSampleIds = array_column($newRecommendations, 'sample_id');
      $currentFeed = UserFeedRecommendation::where('user_id', $userId)
        ->whereNotIn('sample_id', $newSampleIds)
        ->orderBy('score', 'desc')
        ->get()
        ->toArray();

      $combinedFeed = array_merge($newRecommendations, $currentFeed);
      usort($combinedFeed, fn($a, $b) => $b['score'] <=> $a['score']);
      $finalFeed = array_slice($combinedFeed, 0, self::FEED_SIZE);
     
      LogHelper::info('quick-update', 'Inyectando en el feed.', [
        'user_id' => $userId,
        'new_recommendations_count' => count($newRecommendations),
        'final_feed_size' => count($finalFeed)
      ]);

      UserFeedRecommendation::where('user_id', $userId)->delete();
      UserFeedRecommendation::insert($finalFeed);
    });
  }
}