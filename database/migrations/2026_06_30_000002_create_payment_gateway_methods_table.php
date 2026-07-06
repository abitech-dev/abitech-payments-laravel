<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection;

    public function __construct()
    {
        $this->connection = config('abitech_payments.connection');
    }

    public function up(): void
    {
        $keyType = config('abitech_payments.primary_key_type', 'uuid');

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
            $table->string('code', 50)->nullable()->comment('Codigo corto: mp_checkout, stripe_checkout');
            $table->string('label', 100)->nullable()->comment('Nombre publico: Mercado Pago, Stripe');
            $table->string('description', 255)->nullable()->comment('Descripcion publica');
            $table->boolean('is_active')->default(true)->comment('Estado activo registro');
            $table->decimal('min_amount', 12, 2)->default(0.00)->comment('Monto minimo admitido');
            $table->decimal('max_amount', 12, 2)->default(99999999.99)->comment('Monto maximo admitido');
            $table->json('options')->nullable()->comment('Configuracion especifica del metodo (payment_methods, cuotas, etc.)');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('gateway_id')
                ->references('id')
                ->on('payment_gateways')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('payment_gateway_methods');
    }
};
