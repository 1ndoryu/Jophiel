<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class UserInteraction
 * @package app\model
 *
 * @property int $id
 * @property int $user_id
 * @property int $sample_id
 * @property string $interaction_type
 * @property float $weight
 * @property string|null $processed_at
 */
class UserInteraction extends Model
{
    protected $table = 'user_interactions';

    // La tabla solo tiene created_at, deshabilitamos updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'sample_id',
        'interaction_type',
        'weight',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'processed_at' => 'datetime',
        'weight' => 'float',
    ];
}