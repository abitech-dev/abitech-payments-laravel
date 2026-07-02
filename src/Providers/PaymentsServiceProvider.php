<?php

declare(strict_types=1);

namespace Abitech\Payments\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Contracts\ConfigResolverInterface;
use Abitech\Payments\Http\Middleware\EnforceIdempotencyKey;

/**
 * ServiceProvider del paquete abitech/payments-laravel.
 *
 * Registra el PaymentManager como singleton, inyecta el ConfigResolver
 * si esta disponible, publica archivos de configuracion y migraciones,
 * y registra el middleware de idempotencia.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/abitech_payments.php',
            'abitech_payments'
        );

        $this->app->singleton(PaymentManager::class, function () {
            return new PaymentManager($this->app);
        });

        // Inyecta ConfigResolver en el manager cuando se resuelva,
        // despues de que todos los providers hayan registrado sus bindings.
        $this->app->resolving(PaymentManager::class, function (PaymentManager $manager) {
            if ($this->app->bound(ConfigResolverInterface::class)) {
                $manager->setConfigResolver($this->app->make(ConfigResolverInterface::class));
            }
        });
    }

    public function boot(): void
    {
        $this->registerMiddleware();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/abitech_payments.php' => config_path('abitech_payments.php'),
            ], 'abitech-payments-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'abitech-payments-migrations');
        }
    }

    /**
     * Registra el middleware de idempotencia como alias 'abitech.idempotency'.
     * La Host App lo aplica en rutas especificas via ->middleware('abitech.idempotency').
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        if (method_exists($router, 'aliasMiddleware')) {
            $router->aliasMiddleware('abitech.idempotency', EnforceIdempotencyKey::class);
        }
    }
}
