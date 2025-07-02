<?php

/**
 * Definición del handler maestro para centralizar todos los logs.
 * Este handler se añadirá a todos los demás canales.
 */
$master_handler = [
    'class' => Monolog\Handler\RotatingFileHandler::class,
    'constructor' => [
        runtime_path() . '/logs/jophiel-master.log', // Archivo de log maestro
        30, // Mantener 30 archivos de log
        Monolog\Logger::DEBUG, // Loguear todos los niveles
    ],
    'formatter' => [
        'class' => Monolog\Formatter\LineFormatter::class,
        'constructor' => [
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true, // allowInlineLineBreaks
            true  // ignoreEmptyContextAndExtra
        ],
    ],
];

return [
    // El log por defecto de webman. También enviará al log maestro.
    'default' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/webman.log',
                    7, //$maxFiles
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [null, 'Y-m-d H:i:s', true],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal exclusivo para el log maestro. Útil para logs generales.
    'master' => [
        'handlers' => [
            $master_handler,
        ],
    ],

    // Canal de log para el proceso batch de Jophiel
    'batch-process' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-batch.log',
                    10,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal de log para métricas de rendimiento
    'performance' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-performance.log',
                    5,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal de log para los comandos CLI manuales
    'batch-command' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-command.log',
                    5,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal de log para la Reacción Inmediata (Quick Update)
    'quick-update' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-quick-update.log',
                    5,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal de log para el test de Reacción Inmediata
    'quick-update-test' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-quick-update-test.log',
                    2,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal para el consumidor de RabbitMQ
    'rabbitmq-consumer' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-rabbitmq.log',
                    10,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal para el servicio de vectorización
    'vectorization_service' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-vectorization.log',
                    10,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // <-- AÑADIDO: Loguear a maestro
        ],
    ],

    // Canal de log para el enrutador de eventos
    'event-router' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-event-router.log',
                    5,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler,
        ],
    ],
    'sync-controller-debug' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-sync-debug.log',
                    2, // maxFiles
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        "[%datetime%] %level_name%: %message% %context% %extra%\n",
                        'Y-m-d H:i:s',
                        true,
                        true
                    ],
                ],
            ],
            $master_handler, // También enviar al log maestro
        ],
    ],
];
