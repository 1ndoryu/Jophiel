<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class SampleVector
 * @package app\model
 *
 * @property int $sample_id
 * @property string $vector
 */
class SampleVector extends Model
{
    protected $table = 'sample_vectors';
    protected $primaryKey = 'sample_id';
    public $incrementing = false;

    protected $fillable = [
        'sample_id',
        'vector',
    ];
}