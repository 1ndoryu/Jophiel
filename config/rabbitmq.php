<?php

/**
 * Configuración para la conexión con RabbitMQ.
 */
return [
    // Conexión por defecto
    'default' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'insist' => false,
        'login_method' => 'AMQPLAIN',
        'login_response' => null,
        'locale' => 'en_US',
        'connection_timeout' => 3.0,
        'read_write_timeout' => 130, // Mayor que el heartbeat
        'keepalive' => true,
        'heartbeat' => 60,
    ],

    // Definiciones específicas de Jophiel
    'jophiel_consumer' => [
        'exchange_name' => 'sword_events',
        'queue_name' => 'jophiel_consumer_queue',
        'routing_keys' => [
            'user.interaction.*',
            'sample.lifecycle.*'
        ],
    ],

    'log_channel' => 'rabbitmq-consumer',
];
