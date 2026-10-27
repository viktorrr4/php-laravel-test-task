<?php

namespace App\Providers;

use App\Support\WebhookSignatureVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WebhookSignatureVerifier::class, function ($app): WebhookSignatureVerifier {
            $config = $app['config'];

            return new WebhookSignatureVerifier(
                secret: (string) $config->get('services.marketplace.webhook_secret', ''),
                tolerance: (int) $config->get('services.marketplace.webhook_tolerance', 300),
            );
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
