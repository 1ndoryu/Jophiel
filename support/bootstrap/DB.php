<?php

namespace support\bootstrap;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Webman\Bootstrap;

class DB implements Bootstrap
{
    public static function start($worker)
    {
        // No es necesario inicializar en el proceso monitor
        if ($worker && $worker->name == 'monitor') {
            return;
        }

        $capsule = new Capsule;
        $configs = config('database');

        $default_config = $configs['connections'][$configs['default']];
        $capsule->addConnection($default_config);

        foreach ($configs['connections'] as $name => $config) {
            $capsule->addConnection($config, $name);
        }

        // Configurar el despachador de eventos
        $capsule->setEventDispatcher(new Dispatcher(new Container));

        // Hacer que esta instancia de Capsule esté disponible globalmente a través de métodos estáticos
        $capsule->setAsGlobal();

        // Iniciar Eloquent
        $capsule->bootEloquent();
    }
}
