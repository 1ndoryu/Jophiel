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
    ];
}