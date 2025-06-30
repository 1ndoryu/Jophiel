<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class SampleVector
 * @package app\model
 *
 * @property int $sample_id
 * @property int $creator_id
 * @property array $vector
 */
class SampleVector extends Model
{
    protected $table = 'sample_vectors';
    protected $primaryKey = 'sample_id';
    public $incrementing = false;

    protected $fillable = [
        'sample_id',
        'creator_id',
        'vector',
    ];

    protected $casts = [
        'vector' => 'array',
    ];
}