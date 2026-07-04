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
        Schema::connection($this->connection)->create('currencies', function (Blueprint $table) {
            $table->string('code', 3)->primary()->comment('Codigo ISO moneda');
            $table->string('name', 100)->comment('Nombre de moneda');
            $table->string('symbol', 10)->comment('Simbolo de moneda');
            $table->integer('decimals')->default(2)->comment('Decimales de moneda');
            $table->boolean('is_active')->default(true)->comment('Estado activo registro');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('currencies');
    }
};
