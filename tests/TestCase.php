<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Abitech\Payments\Providers\PaymentsServiceProvider;
use Illuminate\Support\Facades\Event;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }

    /**
     * Falsea solo los eventos del paquete; el limitador dispara los de caché.
     */
    protected function fakePackageEvents(): void
    {
        Event::fake([fn (string $event) => str_starts_with($event, 'Abitech\\Payments\\Events\\')]);
    }
}
