<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature;

use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\TestCase;

class PaymentManagerTest extends TestCase
{
    protected PaymentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->app->make(PaymentManager::class);
    }

    public function test_it_registers_fake_driver(): void
    {
        $driver = $this->manager->driver('fake');
        $this->assertInstanceOf(\Abitech\Payments\Drivers\Testing\FakePaymentDriver::class, $driver);
    }

    public function test_it_extends_laravel_manager(): void
    {
        $this->assertInstanceOf(\Illuminate\Support\Manager::class, $this->manager);
    }

    public function test_it_returns_default_driver(): void
    {
        $default = $this->manager->getDefaultDriver();
        $this->assertIsString($default);
    }

    public function test_for_tenant_creates_new_instance(): void
    {
        $tenant = $this->manager->forTenant('tenant-1');
        $this->assertInstanceOf(PaymentManager::class, $tenant);
        $this->assertNotSame($this->manager, $tenant);
    }
}
