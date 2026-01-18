<?php

namespace App\Providers;

use App\Services\MediaConvertService;
use App\Services\WebhookService;
use App\Services\ZencoderTranslatorService;
use Illuminate\Support\ServiceProvider;

class AwsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MediaConvertService::class, function ($app) {
            return new MediaConvertService();
        });

        $this->app->singleton(ZencoderTranslatorService::class, function ($app) {
            return new ZencoderTranslatorService();
        });

        $this->app->singleton(WebhookService::class, function ($app) {
            return new WebhookService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
