<?php

namespace HxDev\UpiMerchant;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class MerchantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hx-upi.php', 'hx-upi');
        $this->app->singleton(LaravelPaymentHandler::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/hx-upi.php' => config_path('hx-upi.php')], 'hx-upi-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'hx-upi-migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePayments::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('hx-upi:reconcile')->dailyAt('03:00')->withoutOverlapping();
            });
        }
    }
}
