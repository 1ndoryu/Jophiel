<?php


return [
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
            ]
        ],
    ],
    // Canal de log específico para el proceso batch de Jophiel
    'batch-process' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-batch.log', // Nombre de archivo específico
                    10, // Mantener 10 archivos de log
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
            ]
        ],
    ],
    // Canal de log específico para métricas de rendimiento
    'performance' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-performance.log', // Archivo separado
                    5, // Mantener 5 archivos
                    Monolog\Logger::DEBUG,
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
            ]
        ],
    ],
    // Canal de log para los comandos CLI manuales
    'batch-command' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/jophiel-command.log', // Archivo separado para comandos
                    5, // Mantener 5 archivos
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
            ]
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
            ]
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
            ]
        ],
    ],
];