<?php

declare(strict_types=1);

namespace Abitech\Payments\Providers;

use Illuminate\Support\ServiceProvider;
use Abitech\Payments\PaymentManager;

class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fusionar la configuración del paquete
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/abitech_payments.php',
            'abitech_payments'
        );

        // Registrar el PaymentManager como Singleton
        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publicar los assets si se ejecuta en consola
        if ($this->app->runningInConsole()) {
            // Configuración
            $this->publishes([
                __DIR__ . '/../../config/abitech_payments.php' => config_path('abitech_payments.php'),
            ], 'abitech-payments-config');

            // Migraciones base
            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'abitech-payments-migrations');
        }
    }
}
