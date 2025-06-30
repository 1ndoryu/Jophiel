<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class UserFeedRecommendation
 * @package app\model
 *
 * @property int $user_id
 * @property int $sample_id
 * @property float $score
 * @property string $generated_at
 */
class UserFeedRecommendation extends Model
{
    public $timestamps = false;
    protected $table = 'user_feed_recommendations';
    // Eloquent no soporta claves primarias compuestas de forma nativa.
    // Se gestionará a nivel de aplicación.
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'sample_id',
        'score',
        'generated_at',
    ];
}