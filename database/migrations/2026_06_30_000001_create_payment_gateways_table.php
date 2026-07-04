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

        Schema::connection($this->connection)->create('payment_gateways', function (Blueprint $table) use ($keyType) {
            if ($keyType === 'uuid') {
                $table->uuid('id')->primary();
            } else {
                $table->bigIncrements('id');
            }
            $table->string('name', 100)->comment('Nombre de pasarela');
            $table->boolean('is_active')->default(true)->comment('Estado activo registro');
            $table->text('credentials')->nullable()->comment('Credenciales cifradas pasarela');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('payment_gateways');
    }
};
