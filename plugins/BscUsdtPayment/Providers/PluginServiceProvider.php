<?php

namespace Plugin\Bscusdtpayment\Providers;

use Illuminate\Support\ServiceProvider;
use Plugin\Bscusdtpayment\Commands\CheckBscUsdtPayment;

class PluginServiceProvider extends ServiceProvider
{
    public function register()
    {
        // 注册命令
        $this->commands([
            CheckBscUsdtPayment::class,
        ]);
    }

    public function boot()
    {
        // 注册调度任务
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('check:bsc-usdt-payment')->everyFiveMinutes();
        });
    }
}
