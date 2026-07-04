<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Abitech\Payments\Providers\PaymentsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }
}
