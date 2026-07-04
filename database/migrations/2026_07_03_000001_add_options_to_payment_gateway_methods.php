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
        Schema::connection($this->connection)->table('payment_gateway_methods', function (Blueprint $table) {
            $table->json('options')->nullable()->comment('Configuracion especifica del metodo (payment_methods, cuotas, etc.)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('payment_gateway_methods', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
