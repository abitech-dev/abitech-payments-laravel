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
