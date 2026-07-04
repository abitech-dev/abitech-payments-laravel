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
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('payment_method_currency');
    }
};
