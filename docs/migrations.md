# Migraciones publicables

El paquete incluye migraciones de infraestructura base. Una vez publicadas, la app host debe ejecutarlas, adaptarlas si es necesario y crear los modelos Eloquent correspondientes.

## Publicacion

```bash
php artisan vendor:publish --tag=abitech-payments-migrations
php artisan migrate
```

## Tablas creadas

| Tabla | Descripcion |
|-------|-------------|
| `currencies` | Catalogo de divisas (PEN, USD, EUR, ...) |
| `payment_gateways` | Pasarelas registradas (MercadoPago, Stripe, ...) |
| `payment_gateway_methods` | Metodos de pago por pasarela (Checkout Pro, API, ...) |
| `payment_method_currency` | Pivot: metodos de pago ↔ divisas soportadas |
| `incoming_webhook_logs` | Auditoria de webhooks recibidos |

## Personalizacion del tipado de PK

El tipo de clave primaria se define en `config/abitech_payments.php`:

```php
'primary_key_type' => env('ABITECH_PAYMENTS_KEY_TYPE', 'uuid'),
```

Opciones: `uuid` o `int`. Las migraciones publicables usan este valor dinamicamente para generar `UUID` o `bigIncrements`.

## Personalizacion de conexion de BD

```php
'connection' => env('ABITECH_PAYMENTS_DB_CONNECTION', null),
```

Si se especifica una conexion, las tablas de infraestructura se crean en esa BD separada.

## Modelos Eloquent en la Host App

Despues de migrar, la app host debe crear los modelos. Ejemplo:

```php
// app/Models/PaymentGateway.php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // solo si primary_key_type = uuid

class PaymentGateway extends Model
{
    use SoftDeletes;
    // use HasUuids; // descomentar si usas UUID

    protected $fillable = ['name', 'is_active', 'credentials'];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'encrypted:json', // ESTO ES OBLIGATORIO - Standard #8
    ];

    public function methods()
    {
        return $this->hasMany(PaymentGatewayMethod::class);
    }
}
```

```php
// app/Models/PaymentGatewayMethod.php

class PaymentGatewayMethod extends Model
{
    use SoftDeletes;
    // use HasUuids;

    protected $fillable = [
        'payment_gateway_id', 'name', 'payment_type',
        'is_active', 'min_amount', 'max_amount',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
    ];

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    public function currencies()
    {
        return $this->belongsToMany(Currency::class, 'payment_method_currency', 'payment_method_id', 'currency_code');
    }
}
```

```php
// app/Models/IncomingWebhookLog.php

class IncomingWebhookLog extends Model
{
    // use HasUuids;

    const UPDATED_AT = null; // solo created_at

    protected $fillable = ['gateway', 'payload', 'headers', 'status', 'error_message'];

    protected $casts = [
        'payload' => 'json',
        'headers' => 'json',
    ];
}
```

## Adaptacion de migraciones

Una vez publicadas, la app host puede modificar las migraciones libremente (agregar columnas, indices, etc.). Las migraciones originales del paquete son solo plantillas de infraestructura.

## Tablas de negocio (NO incluidas en el paquete)

La app host debe crear manualmente las migraciones de negocio:

- `ledger` — libro contable de transacciones
- `invoices` — facturas
- `wallets` — billeteras/saldos de usuarios
- `subscriptions` — suscripciones activas
- `balances` — balances por moneda

Estas tablas son exclusivas de la logica de negocio y **no** pertenecen al paquete.
