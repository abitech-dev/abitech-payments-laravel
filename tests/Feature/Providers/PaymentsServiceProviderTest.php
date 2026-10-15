<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Providers;

use Abitech\Payments\Contracts\ConfigResolverInterface;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Providers\PaymentsServiceProvider;
use Abitech\Payments\Resolvers\DatabaseConfigResolver;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class PaymentsServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    public function test_the_config_is_published_with_its_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(PaymentsServiceProvider::class, 'abitech-payments-config');

        $this->assertSame([config_path('abitech_payments.php')], array_values($paths));
        $this->assertFileExists(array_key_first($paths));
    }

    public function test_the_migrations_are_published_with_their_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(PaymentsServiceProvider::class, 'abitech-payments-migrations');

        $this->assertSame([database_path('migrations')], array_values($paths));
        $this->assertDirectoryExists(array_key_first($paths));
    }

    public function test_the_package_config_is_merged(): void
    {
        $this->assertSame('mercadopago_checkout', config('abitech_payments.default'));
        $this->assertSame([429, 500, 502, 503, 504], config('abitech_payments.retry.retryable_http_codes'));
    }

    public function test_a_bound_config_resolver_supplies_the_driver_credentials(): void
    {
        $this->app->instance(ConfigResolverInterface::class, new DatabaseConfigResolver(
            fn (string $gateway) => ['secret' => "sk_resuelta_{$gateway}"],
        ));
        $stripe = FakeStripeHttpClient::install([[200, ['object' => 'balance']]]);

        $this->app->make(PaymentManager::class)->driver('stripe_paymentintents')->health();

        $this->assertContains('Authorization: Bearer sk_resuelta_stripe', $stripe->headersOf(0));
    }
}
