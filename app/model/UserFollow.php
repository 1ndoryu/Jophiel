<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class UserFollow
 * @package app\model
 *
 * @property int $user_id
 * @property int $followed_user_id
 */
class UserFollow extends Model
{
    public $timestamps = false; // Solo usamos created_at que se maneja en la DB
    protected $table = 'user_follows';
    // Eloquent no soporta claves primarias compuestas de forma nativa.
    // Se gestionará a nivel de aplicación.
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'followed_user_id',
    ];
}
