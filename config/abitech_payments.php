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

    /*
    |--------------------------------------------------------------------------
    | Esquemas de Configuracion de Metodos de Pago
    |--------------------------------------------------------------------------
    | Define los campos configurables por el admin para cada driver.
    | El frontend renderiza estos schemas dinamicamente sin hardcodear
    | opciones especificas de cada pasarela.
    |
    | Cada campo soporta:
    |   - key:       nombre del campo en options.payment_methods
    |   - type:      number | checkboxes (array de strings con id/label)
    |   - label:     etiqueta visible en el admin
    |   - default:   valor por defecto
    |   - min/max:   solo para type=number
    |   - options:   solo para type=checkboxes [{id, label}]
    */
    'payment_method_schemas' => [
        'mercadopago_checkout' => [
            'fields' => [
                [
                    'key' => 'installments',
                    'type' => 'number',
                    'label' => 'Cuotas máximas permitidas',
                    'min' => 1,
                    'max' => 12,
                    'default' => 12,
                ],
                [
                    'key' => 'excluded_payment_types',
                    'type' => 'checkboxes',
                    'label' => 'Excluir tipos de pago',
                    'default' => [],
                    'options' => [
                        ['id' => 'ticket', 'label' => 'Efectivo (PagoEfectivo, OXXO, etc.)'],
                        ['id' => 'atm', 'label' => 'Cajero automático'],
                        ['id' => 'bank_transfer', 'label' => 'Transferencia bancaria'],
                        ['id' => 'prepaid_card', 'label' => 'Tarjeta prepago'],
                        ['id' => 'debit_card', 'label' => 'Tarjeta de débito'],
                    ],
                ],
                [
                    'key' => 'excluded_payment_methods',
                    'type' => 'checkboxes',
                    'label' => 'Excluir marcas de tarjeta / billeteras',
                    'default' => [],
                    'options' => [
                        ['id' => 'visa', 'label' => 'Visa'],
                        ['id' => 'master', 'label' => 'Mastercard'],
                        ['id' => 'amex', 'label' => 'American Express'],
                        ['id' => 'diners', 'label' => 'Diners Club'],
                        ['id' => 'yape', 'label' => 'Yape'],
                        ['id' => 'pagoefectivo_atm', 'label' => 'PagoEfectivo'],
                    ],
                ],
            ],
        ],
        'mercadopago_api' => [
            'fields' => [
                [
                    'key' => 'installments',
                    'type' => 'number',
                    'label' => 'Cuotas máximas permitidas',
                    'min' => 1,
                    'max' => 12,
                    'default' => 12,
                ],
                [
                    'key' => 'excluded_payment_methods',
                    'type' => 'checkboxes',
                    'label' => 'Excluir marcas de tarjeta',
                    'default' => [],
                    'options' => [
                        ['id' => 'visa', 'label' => 'Visa'],
                        ['id' => 'master', 'label' => 'Mastercard'],
                        ['id' => 'amex', 'label' => 'American Express'],
                        ['id' => 'diners', 'label' => 'Diners Club'],
                    ],
                ],
            ],
        ],
        'stripe_checkout' => [
            'fields' => [
                [
                    'key' => 'payment_method_types',
                    'type' => 'checkboxes',
                    'label' => 'Métodos de pago habilitados',
                    'default' => ['card'],
                    'options' => [
                        ['id' => 'card', 'label' => 'Tarjeta de crédito/débito'],
                        ['id' => 'ideal', 'label' => 'iDEAL'],
                        ['id' => 'bancontact', 'label' => 'Bancontact'],
                        ['id' => 'sofort', 'label' => 'SOFORT'],
                        ['id' => 'sepa_debit', 'label' => 'SEPA Direct Debit'],
                    ],
                ],
            ],
        ],
        'stripe_paymentintents' => [
            'fields' => [
                [
                    'key' => 'payment_method_types',
                    'type' => 'checkboxes',
                    'label' => 'Métodos de pago habilitados',
                    'default' => ['card'],
                    'options' => [
                        ['id' => 'card', 'label' => 'Tarjeta de crédito/débito'],
                        ['id' => 'ideal', 'label' => 'iDEAL'],
                        ['id' => 'bancontact', 'label' => 'Bancontact'],
                    ],
                ],
            ],
        ],
    ],
];
