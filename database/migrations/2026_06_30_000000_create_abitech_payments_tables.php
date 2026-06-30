<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conexión de base de datos a usar.
     */
    protected $connection;

    /**
     * Crear una nueva instancia de la migración.
     */
    public function __construct()
    {
        $this->connection = config('abitech_payments.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $keyType = config('abitech_payments.primary_key_type', 'uuid');

        // 1. Tabla de Monedas
        Schema::connection($this->connection)->create('currencies', function (Blueprint $table) {
            $table->string('code', 3)->primary()->comment('Codigo ISO moneda');
            $table->string('name', 100)->comment('Nombre de moneda');
            $table->string('symbol', 10)->comment('Simbolo de moneda');
            $table->integer('decimals')->default(2)->comment('Decimales de moneda');
            $table->boolean('is_active')->default(true)->comment('Estado activo registro');
            $table->softDeletes();
            $table->timestamps();
        });

        // 2. Tabla de Pasarelas de Pago
        Schema::connection($this->connection)->create('payment_gateways', function (Blueprint $table) use ($keyType) {
            if ($keyType === 'uuid') {
                $table->uuid('id')->primary();
            } else {
                $table->bigIncrements('id');
            }
            $table->string('name', 100)->comment('Nombre de pasarela');
            $table->boolean('is_active')->default(true)->comment('Estado activo registro');
            $table->json('credentials')->nullable()->comment('Credenciales cifradas pasarela');
            $table->softDeletes();
            $table->timestamps();
        });

        // 3. Tabla de Modalidades / Métodos Específicos
        Schema::connection($this->connection)->create('payment_gateway_methods', function (Blueprint $table) use ($keyType) {
            if ($keyType === 'uuid') {
                $table->uuid('id')->primary();
                $table->uuid('gateway_id')->comment('Identificador de pasarela');
            } else {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('gateway_id')->comment('Identificador de pasarela');
            }
            $table->string('name', 100)->comment('Nombre de modalidad');
            $table->string('payment_type', 50)->comment('Tipo de pago');
            $table->boolean('is_active')->default(true)->comment('Estado activo registro');
            $table->decimal('min_amount', 12, 2)->default(0.00)->comment('Monto minimo admitido');
            $table->decimal('max_amount', 12, 2)->default(99999999.99)->comment('Monto maximo admitido');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('gateway_id')
                ->references('id')
                ->on('payment_gateways')
                ->cascadeOnDelete();
        });

        // 4. Tabla Pivot de Monedas por Método
        Schema::connection($this->connection)->create('payment_method_currency', function (Blueprint $table) use ($keyType) {
            if ($keyType === 'uuid') {
                $table->uuid('payment_method_id')->comment('Identificador de metodo');
            } else {
                $table->unsignedBigInteger('payment_method_id')->comment('Identificador de metodo');
            }
            $table->string('currency_code', 3)->comment('Codigo de moneda');

            $table->primary(['payment_method_id', 'currency_code']);

            $table->foreign('payment_method_id')
                ->references('id')
                ->on('payment_gateway_methods')
                ->cascadeOnDelete();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->cascadeOnDelete();
        });

        // 5. Tabla de Auditoría de Webhooks Entrantes
        Schema::connection($this->connection)->create('incoming_webhook_logs', function (Blueprint $table) use ($keyType) {
            if ($keyType === 'uuid') {
                $table->uuid('id')->primary();
            } else {
                $table->bigIncrements('id');
            }
            $table->string('gateway', 50)->comment('Nombre de pasarela');
            $table->json('payload')->comment('Cuerpo del webhook');
            $table->json('headers')->comment('Cabeceras del webhook');
            $table->string('status', 50)->default('received')->comment('Estado del procesamiento');
            $table->text('error_message')->nullable()->comment('Detalle del error');
            $table->timestamp('created_at')->nullable()->comment('Fecha de registro');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('incoming_webhook_logs');
        Schema::connection($this->connection)->dropIfExists('payment_method_currency');
        Schema::connection($this->connection)->dropIfExists('payment_gateway_methods');
        Schema::connection($this->connection)->dropIfExists('payment_gateways');
        Schema::connection($this->connection)->dropIfExists('currencies');
    }
};
