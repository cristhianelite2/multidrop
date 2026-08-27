<?php

namespace App\Providers;

use App\Models\CrossSellRule;
use App\Models\PlatformSetting;
use App\Models\RouletteSlide;
use App\Models\UpsellRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Blade::if('canperm', function (string ...$permissions) {
            $user = Auth::user();
            if (! $user) {
                return false;
            }

            return $user->isSuperuser() || $user->hasAnyPermission($permissions);
        });

        Route::bind('upsell', fn ($value) => UpsellRule::query()->findOrFail($value));
        Route::bind('cross_sell', fn ($value) => CrossSellRule::query()->findOrFail($value));
        Route::bind('roulette', fn ($value) => RouletteSlide::query()->findOrFail($value));

        $this->applyPaymentSettingsFromDb();
        $this->applyCjSettingsFromDb();
        $this->applyAiSettingsFromDb();
        $this->applyMailSettingsFromDb();
    }

    protected function applyMailSettingsFromDb(): void
    {
        try {
            app(\App\Services\Platform\PlatformMailSettings::class)->applyToConfig();
        } catch (\Throwable) {
            //
        }
    }

    protected function applyPaymentSettingsFromDb(): void
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $default = PlatformSetting::getValue('payments.default');
        if ($default) {
            config(['payments.default' => $default]);
        }

        // Credenciales globales (default/enabled por tienda viven en stores.settings)
        $map = [
            'payments.stripe.key' => 'payments.stripe.key',
            'payments.stripe.secret' => 'payments.stripe.secret',
            'payments.stripe.webhook_secret' => 'payments.stripe.webhook_secret',
            'payments.paypal.client_id' => 'payments.paypal.client_id',
            'payments.paypal.client_secret' => 'payments.paypal.client_secret',
            'payments.paypal.mode' => 'payments.paypal.mode',
            'payments.mercadopago.public_key' => 'payments.mercadopago.public_key',
            'payments.mercadopago.access_token' => 'payments.mercadopago.access_token',
            'payments.mercadopago.webhook_secret' => 'payments.mercadopago.webhook_secret',
        ];

        foreach ($map as $dbKey => $configKey) {
            $value = PlatformSetting::getValue($dbKey);
            if ($value !== null && $value !== '') {
                config([$configKey => $value]);
            }
        }
    }

    protected function applyCjSettingsFromDb(): void
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $map = [
            'cj.email' => 'cj.email',
            'cj.api_key' => 'cj.api_key',
            'cj.access_token' => 'cj.access_token',
            'cj.refresh_token' => 'cj.refresh_token',
        ];

        foreach ($map as $dbKey => $configKey) {
            $value = PlatformSetting::getValue($dbKey);
            if ($value !== null && $value !== '') {
                config([$configKey => $value]);
            }
        }
    }

    protected function applyAiSettingsFromDb(): void
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $map = [
            'ai.openai.api_key' => 'ai.providers.openai.api_key',
            'ai.openai.base_url' => 'ai.providers.openai.base_url',
            'ai.openai.model' => 'ai.providers.openai.model',
            'ai.miia.api_key' => 'ai.providers.miia.api_key',
            'ai.miia.base_url' => 'ai.providers.miia.base_url',
            'ai.miia.model' => 'ai.providers.miia.model',
        ];

        foreach ($map as $dbKey => $configKey) {
            $value = PlatformSetting::getValue($dbKey);
            if ($value !== null && $value !== '') {
                config([$configKey => $value]);
            }
        }

        $rawTasks = PlatformSetting::getValue('ai.task_engines');
        if ($rawTasks) {
            $decoded = json_decode($rawTasks, true);
            if (is_array($decoded) && $decoded !== []) {
                config(['ai.task_engines' => $decoded]);
            }
        }
    }
}
