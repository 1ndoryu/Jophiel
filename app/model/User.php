<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class User
 * @package app\model
 *
 * @property int $id
 * @property string $name
 * @property string $email
 */
class User extends Model
{
    public $timestamps = true;
    protected $table = 'users';

    protected $fillable = [
        'id',
        'name',
        'email',
    ];
} 