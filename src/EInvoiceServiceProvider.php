<?php

namespace EInvoiceSdk;

use EInvoiceSdk\Contracts\EInvoiceDriver;
use EInvoiceSdk\Drivers\FakeDriver;
use EInvoiceSdk\Drivers\JianniusDriver;
use Illuminate\Support\ServiceProvider;

class EInvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/einvoice.php', 'einvoice');

        $this->app->singleton(EInvoiceDriver::class, fn () => match (config('einvoice.driver')) {
            'fake' => new FakeDriver,
            default => new JianniusDriver,
        });
        $this->app->singleton(EInvoice::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([__DIR__.'/../config/einvoice.php' => config_path('einvoice.php')], 'einvoice-config');
    }
}
