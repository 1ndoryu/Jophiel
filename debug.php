<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/vendor/illuminate/support/helpers.php';
require __DIR__.'/support/bootstrap.php';

use app\model\SampleVector;

foreach (SampleVector::all() as $v) {
    echo $v->sample_id . ' | ' . ($v->vector[1] ?? 'null') . ' | ' . ($v->vector[23] ?? 'null') . PHP_EOL;
}
