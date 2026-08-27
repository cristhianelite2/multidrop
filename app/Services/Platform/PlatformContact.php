<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;

/**
 * Datos de contacto globales de la plataforma (agradecimiento, reclamos, correos).
 */
class PlatformContact
{
    /**
     * @return array{
     *   email: ?string,
     *   phone: ?string,
     *   whatsapp: ?string,
     *   hours: ?string,
     *   note: ?string
     * }
     */
    public function all(): array
    {
        return [
            'email' => $this->str('platform.contact.email'),
            'phone' => $this->str('platform.contact.phone'),
            'whatsapp' => $this->normalizeWhatsapp($this->str('platform.contact.whatsapp')),
            'hours' => $this->str('platform.contact.hours'),
            'note' => $this->str('platform.contact.note'),
        ];
    }

    public function hasAny(): bool
    {
        $c = $this->all();

        return ($c['email'] ?? '') !== ''
            || ($c['phone'] ?? '') !== ''
            || ($c['whatsapp'] ?? '') !== ''
            || ($c['note'] ?? '') !== '';
    }

    public function whatsappUrl(): ?string
    {
        $n = $this->all()['whatsapp'] ?? null;
        if (! $n) {
            return null;
        }

        return 'https://wa.me/'.$n;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        PlatformSetting::put('platform.contact.email', $this->nullable($data['email'] ?? null), 'platform');
        PlatformSetting::put('platform.contact.phone', $this->nullable($data['phone'] ?? null), 'platform');
        PlatformSetting::put('platform.contact.whatsapp', $this->nullable($data['whatsapp'] ?? null), 'platform');
        PlatformSetting::put('platform.contact.hours', $this->nullable($data['hours'] ?? null), 'platform');
        PlatformSetting::put('platform.contact.note', $this->nullable($data['note'] ?? null), 'platform');
    }

    protected function str(string $key): ?string
    {
        $v = trim((string) PlatformSetting::getValue($key, ''));

        return $v !== '' ? $v : null;
    }

    protected function nullable(?string $value): ?string
    {
        $v = trim((string) $value);

        return $v !== '' ? $v : null;
    }

    protected function normalizeWhatsapp(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);

        return $digits !== '' ? $digits : null;
    }
}
