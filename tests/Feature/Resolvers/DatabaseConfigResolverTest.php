<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Resolvers;

use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Resolvers\DatabaseConfigResolver;
use Abitech\Payments\Tests\Doubles\GatewayCredential;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DatabaseConfigResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tenant_id')->nullable();
            $table->boolean('is_active');
            $table->text('credentials');
        });
    }

    public function test_the_model_resolves_the_active_gateway_credentials(): void
    {
        GatewayCredential::create(['name' => 'stripe', 'is_active' => false, 'credentials' => '{"secret":"sk_inactiva"}']);
        GatewayCredential::create(['name' => 'stripe', 'is_active' => true, 'credentials' => '{"secret":"sk_activa"}']);

        $credentials = (new DatabaseConfigResolver(GatewayCredential::class))->resolve('stripe');

        $this->assertSame(['secret' => 'sk_activa'], $credentials);
    }

    public function test_the_model_scopes_the_credentials_to_the_tenant(): void
    {
        GatewayCredential::create(['name' => 'stripe', 'tenant_id' => 'a', 'is_active' => true, 'credentials' => '{"secret":"sk_a"}']);
        GatewayCredential::create(['name' => 'stripe', 'tenant_id' => 'b', 'is_active' => true, 'credentials' => '{"secret":"sk_b"}']);

        $credentials = (new DatabaseConfigResolver(GatewayCredential::class))->resolve('stripe', 'b');

        $this->assertSame(['secret' => 'sk_b'], $credentials);
    }

    public function test_a_gateway_without_active_credentials_throws(): void
    {
        GatewayCredential::create(['name' => 'stripe', 'is_active' => false, 'credentials' => '{}']);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage("No se encontro configuracion activa para la pasarela 'stripe'.");

        (new DatabaseConfigResolver(GatewayCredential::class))->resolve('stripe');
    }

    public function test_the_cache_keeps_the_credentials_until_they_are_forgotten(): void
    {
        $lookups = [];
        $resolver = new DatabaseConfigResolver(function (string $gateway, ?string $tenantId) use (&$lookups) {
            $lookups[] = [$gateway, $tenantId];

            return ['secret' => 'sk_' . count($lookups)];
        }, $this->app['cache']->store('array'));

        $resolver->resolve('stripe', 't1');
        $this->assertSame(['secret' => 'sk_1'], $resolver->resolve('stripe', 't1'));

        $resolver->forget('stripe', 't1');

        $this->assertSame(['secret' => 'sk_2'], $resolver->resolve('stripe', 't1'));
        $this->assertSame([['stripe', 't1'], ['stripe', 't1']], $lookups);
    }

    public function test_each_tenant_has_its_own_cache_entry(): void
    {
        $resolver = new DatabaseConfigResolver(
            fn (string $gateway, ?string $tenantId) => ['secret' => "sk_{$tenantId}"],
            $this->app['cache']->store('array'),
        );

        $this->assertSame(['secret' => 'sk_a'], $resolver->resolve('stripe', 'a'));
        $this->assertSame(['secret' => 'sk_b'], $resolver->resolve('stripe', 'b'));
    }
}
