<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;

/**
 * Configura el mailer de Laravel desde PlatformSetting (Resend / log / smtp).
 * @see https://resend.com/
 */
class PlatformMailSettings
{
    /**
     * @return array{
     *   driver: string,
     *   resend_api_key_set: bool,
     *   from_address: string,
     *   from_name: string,
     *   ready: bool
     * }
     */
    public function status(): array
    {
        $driver = $this->driver();
        $key = PlatformSetting::getValue('platform.mail.resend_api_key') ?: config('services.resend.key');
        $from = $this->fromAddress();

        return [
            'driver' => $driver,
            'resend_api_key_set' => (bool) $key,
            'from_address' => $from,
            'from_name' => $this->fromName(),
            'ready' => $driver !== 'resend' || ((bool) $key && $from !== ''),
        ];
    }

    public function driver(): string
    {
        $d = strtolower(trim((string) PlatformSetting::getValue(
            'platform.mail.driver',
            config('mail.default', 'log')
        )));

        return in_array($d, ['resend', 'log', 'smtp', 'array'], true) ? $d : 'log';
    }

    public function fromAddress(): string
    {
        return trim((string) PlatformSetting::getValue(
            'platform.mail.from_address',
            config('mail.from.address', '')
        ));
    }

    public function fromName(): string
    {
        return trim((string) PlatformSetting::getValue(
            'platform.mail.from_name',
            config('mail.from.name', 'Multidrop')
        )) ?: 'Multidrop';
    }

    /**
     * Aplica settings de DB a config() en runtime.
     */
    public function applyToConfig(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('platform_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $driver = $this->driver();
        $key = PlatformSetting::getValue('platform.mail.resend_api_key');
        if ($key) {
            config([
                'services.resend.key' => $key,
                'mail.mailers.resend.key' => $key,
            ]);
        }

        $fromAddress = $this->fromAddress();
        $fromName = $this->fromName();
        if ($fromAddress !== '') {
            config([
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $fromName,
            ]);
        }

        if ($driver === 'resend' && (config('services.resend.key') || $key)) {
            config(['mail.default' => 'resend']);
        } elseif (in_array($driver, ['log', 'smtp', 'array'], true)) {
            config(['mail.default' => $driver]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        $driver = strtolower(trim((string) ($data['mail_driver'] ?? 'log')));
        if (! in_array($driver, ['resend', 'log', 'smtp', 'array'], true)) {
            $driver = 'log';
        }
        PlatformSetting::put('platform.mail.driver', $driver, 'platform');
        PlatformSetting::put('platform.mail.from_address', trim((string) ($data['mail_from_address'] ?? '')) ?: null, 'platform');
        PlatformSetting::put('platform.mail.from_name', trim((string) ($data['mail_from_name'] ?? '')) ?: null, 'platform');

        $apiKey = $data['resend_api_key'] ?? null;
        if (is_string($apiKey) && $apiKey !== '' && $apiKey !== '********') {
            PlatformSetting::put('platform.mail.resend_api_key', $apiKey, 'platform', true);
        }

        $this->applyToConfig();
    }
}
