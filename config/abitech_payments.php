<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Tipo de Clave Primaria en Migraciones
    |--------------------------------------------------------------------------
    | Define si las tablas del mantenimiento del paquete deben usar claves
    | de tipo UUID o enteros autoincrementales (BigIncrements).
    | Opciones soportadas: 'uuid', 'int'
    */
    'primary_key_type' => env('ABITECH_PAYMENTS_KEY_TYPE', 'uuid'),

    /*
    |--------------------------------------------------------------------------
    | Conexión de Base de Datos
    |--------------------------------------------------------------------------
    | Especifica la conexión a base de datos que deben usar las tablas
    | del paquete. Si es null, se usará la conexión por defecto.
    */
    'connection' => env('ABITECH_PAYMENTS_DB_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Idempotencia
    |--------------------------------------------------------------------------
    | Configuracion de llave de idempotencia para prevenir operaciones duplicadas.
    | La llave se envia via encabezado HTTP X-Idempotency-Key.
    */
    'idempotency' => [
        'enabled' => env('ABITECH_PAYMENTS_IDEMPOTENCY', true),
        'min_length' => 16,
        'max_length' => 255,
        'header' => 'X-Idempotency-Key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pasarela de Pago por Defecto
    |--------------------------------------------------------------------------
    */
    'default' => env('ABITECH_PAYMENTS_DEFAULT', 'mercadopago_checkout'),

    /*
    |--------------------------------------------------------------------------
    | Credenciales por Pasarela
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'mercadopago' => [
            'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            'client_id' => env('MERCADOPAGO_CLIENT_ID'),
            'client_secret' => env('MERCADOPAGO_CLIENT_SECRET'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'),
        ],
        'stripe' => [
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'notification_url' => env('STRIPE_NOTIFICATION_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reintentos de API
    |--------------------------------------------------------------------------
    | Configuracion global de reintentos con backoff exponencial.
    | Puede ser sobrescrito por gateway en la seccion 'gateways.<nombre>'.
    */
    'retry' => [
        'max_attempts' => 3,
        'base_delay_ms' => 300,
        'multiplier' => 2.0,
        'retryable_http_codes' => [429, 500, 502, 503, 504],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | Limite de peticiones por minuto a las APIs de las pasarelas.
    */
    'rate_limit' => [
        'max_requests_per_minute' => 60,
        'cache_prefix' => env('ABITECH_PAYMENTS_CACHE_PREFIX', 'abitech_payments'),
    ],
];
