<?php

namespace Oriclab\EInvoice;

use Illuminate\Support\ServiceProvider;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Drivers\FakeDriver;
use Oriclab\EInvoice\Drivers\JianniusDriver;

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
