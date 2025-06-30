<?php

namespace support\bootstrap;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher; // Aseguramos que la clase Dispatcher se importa
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

        // La conexión por defecto es obligatoria.
        $default_config = $configs['connections'][$configs['default']];
        $capsule->addConnection($default_config);

        foreach ($configs['connections'] as $name => $config) {
            // Evita añadir la conexión por defecto dos veces.
            if ($name === $configs['default']) {
                continue;
            }
            $capsule->addConnection($config, $name);
        }

        // Configurar el despachador de eventos.
        // Esta es la línea que causaba el error.
        $capsule->setEventDispatcher(new Dispatcher(new Container));

        // Hacer que esta instancia de Capsule esté disponible globalmente a través de métodos estáticos.
        $capsule->setAsGlobal();

        // Iniciar Eloquent.
        $capsule->bootEloquent();
    }
}