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
        ],
        'stripe' => [
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],
];
