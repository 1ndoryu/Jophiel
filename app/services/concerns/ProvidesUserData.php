<?php

namespace app\services\concerns;

use app\model\UserFollow;
use app\model\UserInteraction;
use Illuminate\Support\Collection;

/**
 * Trait ProvidesUserData
 *
 * Provides common methods to fetch user-related data like interactions and follows.
 */
trait ProvidesUserData
{
    /**
     * Retrieves sample IDs with which a user has had a "definitive" interaction (like/dislike).
     *
     * @param int $userId
     * @return array
     */
    protected function getUserDefinitiveInteractions(int $userId): array
    {
        return UserInteraction::where('user_id', $userId)
            ->whereIn('interaction_type', ['dislike'])
            ->pluck('sample_id')
            ->all();
    }

    /**
     * Retrieves the IDs of creators followed by a given list of users.
     *
     * @param array $userIds
     * @return Collection A collection grouped by user_id, where each item is a collection of followed_user_id.
     */
    protected function getFollowedCreatorsForUsers(array $userIds): Collection
    {
        return UserFollow::whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id')
            ->map(fn($follows) => $follows->pluck('followed_user_id'));
    }
}
