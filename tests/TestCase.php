<?php

namespace Oriclab\EInvoice\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Drivers\FakeDriver;
use Oriclab\EInvoice\EInvoiceServiceProvider;
use Oriclab\EInvoice\Enums\Environment;
use Oriclab\EInvoice\Models\EInvoiceSetting;

abstract class TestCase extends Orchestra
{
    protected FakeDriver $driver;

    protected function getPackageProviders($app): array
    {
        return [EInvoiceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('test_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->string('type')->default('01');
            $table->string('original_number')->nullable();
            $table->string('original_uuid')->nullable();
            $table->string('buyer_tin')->default('C20830570210');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDriver;
        $this->app->instance(EInvoiceDriver::class, $this->driver);
    }

    protected function setting(Environment $environment = Environment::Sandbox): EInvoiceSetting
    {
        return EInvoiceSetting::create([
            'tin' => Fixtures\TestInvoice::SUPPLIER_TIN,
            'environment' => $environment,
            'client_id' => 'id-'.$environment->value,
            'client_secret' => 'secret',
            'certificate' => 'cert',
            'private_key' => 'key',
        ]);
    }
}
