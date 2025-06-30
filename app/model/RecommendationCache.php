<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class RecommendationCache
 * @package app\model
 *
 * @property string $cache_key
 * @property array $recommended_ids
 * @property string $expires_at
 */
class RecommendationCache extends Model
{
    public $timestamps = false;
    protected $table = 'recommendation_cache';
    protected $primaryKey = 'cache_key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cache_key',
        'recommended_ids',
        'expires_at',
    ];

    protected $casts = [
        'recommended_ids' => 'array',
        'expires_at' => 'datetime',
    ];
}