<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Http\Middleware;

use Abitech\Payments\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class EnforceIdempotencyKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/usuarios', fn () => response()->json(['ok' => true]))->middleware('abitech.idempotency');
    }

    public function test_a_mutation_without_the_header_gets_a_generic_422(): void
    {
        $this->postJson('/usuarios')
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => 'Se requiere el encabezado X-Idempotency-Key.',
                'errors' => null,
            ]);
    }

    public function test_a_mutation_with_a_valid_key_passes(): void
    {
        $this->postJson('/usuarios', [], ['X-Idempotency-Key' => 'clave-de-prueba-0001'])
            ->assertOk();
    }
}
