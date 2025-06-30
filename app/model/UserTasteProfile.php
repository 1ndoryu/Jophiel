<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class UserTasteProfile
 * @package app\model
 *
 * @property int $user_id
 * @property string $taste_vector
 */
class UserTasteProfile extends Model
{
    protected $table = 'user_taste_profiles';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    // La tabla solo tiene updated_at, deshabilitamos created_at
    const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'taste_vector',
    ];
}