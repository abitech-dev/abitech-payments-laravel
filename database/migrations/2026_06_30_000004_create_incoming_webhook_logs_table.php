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
            $table->timestamp('created_at')->useCurrent()->comment('Fecha de registro');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('incoming_webhook_logs');
    }
};
