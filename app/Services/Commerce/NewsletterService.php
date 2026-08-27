<?php

namespace App\Services\Commerce;

use App\Mail\NewsletterConfirmMail;
use App\Mail\NewsletterCouponMail;
use App\Models\Coupon;
use App\Models\NewsletterSubscriber;
use App\Models\Store;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NewsletterService
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *   headline: string,
     *   subtitle: string,
     *   cta: string,
     *   success_message: string,
     *   coupon_type: string,
     *   coupon_value: float,
     *   coupon_days: int,
     *   coupon_prefix: string,
     *   coupon_hint: string,
     *   position: string,
     *   auto_open: bool,
     *   auto_open_delay_ms: int,
     *   checkout_enabled: bool,
     *   checkout_label: string
     * }
     */
    public function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $type = (string) ($raw['coupon_type'] ?? 'percent');
        if (! in_array($type, ['percent', 'fixed'], true)) {
            $type = 'percent';
        }
        $value = max(1, min(10000, (float) ($raw['coupon_value'] ?? 10)));
        if ($type === 'percent') {
            $value = max(1, min(90, $value));
        }
        $days = max(1, min(365, (int) ($raw['coupon_days'] ?? 7)));
        $hint = $type === 'percent'
            ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'% OFF'
            : '$'.rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        $checkoutLabel = trim((string) ($raw['checkout_label'] ?? ''));
        if ($checkoutLabel === '') {
            $checkoutLabel = 'Quiero recibir ofertas y ganar un cupón de {value} para mi próxima compra';
        }

        return [
            'headline' => mb_substr(trim((string) ($raw['headline'] ?? 'Únete y gana un cupón')), 0, 80) ?: 'Únete y gana un cupón',
            'subtitle' => mb_substr(trim((string) ($raw['subtitle'] ?? 'Confirma tu correo y recibe un descuento personalizado')), 0, 200),
            'cta' => mb_substr(trim((string) ($raw['cta'] ?? 'Quiero mi cupón')), 0, 40) ?: 'Quiero mi cupón',
            'success_message' => mb_substr(trim((string) ($raw['success_message'] ?? 'Te enviamos un correo para confirmar. Al confirmar recibirás tu cupón.')), 0, 240),
            'coupon_type' => $type,
            'coupon_value' => $value,
            'coupon_days' => $days,
            'coupon_prefix' => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['coupon_prefix'] ?? 'NL')) ?: 'NL', 0, 8)),
            'coupon_hint' => $hint,
            'position' => in_array(($raw['position'] ?? 'bottom-right'), ['bottom-left', 'bottom-right'], true)
                ? (string) ($raw['position'] ?? 'bottom-right')
                : 'bottom-right',
            'auto_open' => filter_var($raw['auto_open'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'auto_open_delay_ms' => max(800, min(30000, (int) ($raw['auto_open_delay_ms'] ?? 3500))),
            'checkout_enabled' => filter_var($raw['checkout_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'checkout_label' => mb_substr($checkoutLabel, 0, 220),
            'checkout_label_display' => mb_substr(str_replace('{value}', $hint, $checkoutLabel), 0, 220),
        ];
    }

    public function forStore(Store $store): array
    {
        return $this->normalize(data_get($store->settings, 'newsletter'));
    }

    public function forSandbox(): array
    {
        return $this->normalize([
            'headline' => 'Newsletter sandbox',
            'subtitle' => 'Registra un correo, confírmalo y recibe un cupón demo',
            'cta' => 'Obtener cupón',
            'coupon_type' => 'percent',
            'coupon_value' => 10,
            'coupon_days' => 7,
            'coupon_prefix' => 'NL',
            'auto_open' => true,
            'checkout_enabled' => true,
        ]);
    }

    /**
     * @return array{ok: bool, message: string, already?: bool}
     */
    public function subscribe(Store $store, string $email, string $source = 'popup'): array
    {
        if (! $store->pluginEnabled('newsletter')) {
            return ['ok' => false, 'message' => 'Newsletter no está activo.'];
        }

        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Indica un correo válido.'];
        }

        $cfg = $this->forStore($store);
        $existing = NewsletterSubscriber::query()
            ->where('store_id', $store->id)
            ->where('email', $email)
            ->first();

        if ($existing && $existing->isConfirmed()) {
            return [
                'ok' => true,
                'already' => true,
                'message' => $existing->coupon_code
                    ? 'Ya estás suscrito. Tu cupón: '.$existing->coupon_code
                    : 'Este correo ya está confirmado.',
            ];
        }

        $token = Str::random(48);
        $subscriber = $existing ?: new NewsletterSubscriber([
            'store_id' => $store->id,
            'email' => $email,
        ]);
        $subscriber->fill([
            'source' => $source,
            'status' => 'pending',
            'confirm_token' => $token,
            'confirmed_at' => null,
        ]);
        $subscriber->save();

        $confirmUrl = route('store.newsletter.confirm', [
            'slug' => $store->slug,
            'token' => $token,
        ]);

        try {
            Mail::to($email)->send(new NewsletterConfirmMail(
                $store,
                $subscriber,
                $confirmUrl,
                $cfg['coupon_hint']
            ));
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'No se pudo enviar el correo de confirmación.'];
        }

        return [
            'ok' => true,
            'message' => $cfg['success_message'],
        ];
    }

    /**
     * Checkout: marca casilla → confirma al instante (email del pedido) y envía cupón.
     *
     * @return array{ok: bool, message: string, coupon_code?: string|null}
     */
    public function subscribeFromCheckout(Store $store, string $email): array
    {
        if (! $store->pluginEnabled('newsletter')) {
            return ['ok' => false, 'message' => 'Newsletter no activo.'];
        }

        $cfg = $this->forStore($store);
        if (! $cfg['checkout_enabled']) {
            return ['ok' => false, 'message' => 'Opt-in de checkout desactivado.'];
        }

        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Email inválido.'];
        }

        $subscriber = NewsletterSubscriber::query()
            ->where('store_id', $store->id)
            ->where('email', $email)
            ->first();

        if ($subscriber && $subscriber->isConfirmed() && $subscriber->coupon_code) {
            return [
                'ok' => true,
                'message' => 'Ya tenías un cupón de newsletter.',
                'coupon_code' => $subscriber->coupon_code,
            ];
        }

        if (! $subscriber) {
            $subscriber = NewsletterSubscriber::create([
                'store_id' => $store->id,
                'email' => $email,
                'source' => 'checkout',
                'status' => 'pending',
                'confirm_token' => Str::random(48),
            ]);
        } else {
            $subscriber->source = 'checkout';
            $subscriber->save();
        }

        return $this->confirmSubscriber($store, $subscriber, $cfg);
    }

    /**
     * @return array{ok: bool, message: string, coupon_code?: string|null, view?: array<string, mixed>}
     */
    public function confirmByToken(Store $store, string $token): array
    {
        $subscriber = NewsletterSubscriber::query()
            ->where('store_id', $store->id)
            ->where('confirm_token', $token)
            ->first();

        if (! $subscriber) {
            return ['ok' => false, 'message' => 'Enlace inválido o expirado.'];
        }

        $cfg = $this->forStore($store);
        if ($subscriber->isConfirmed() && $subscriber->coupon_code) {
            return [
                'ok' => true,
                'message' => 'Tu suscripción ya estaba confirmada.',
                'coupon_code' => $subscriber->coupon_code,
                'view' => [
                    'store' => $store,
                    'coupon_code' => $subscriber->coupon_code,
                    'coupon_hint' => $cfg['coupon_hint'],
                    'days' => $cfg['coupon_days'],
                ],
            ];
        }

        return $this->confirmSubscriber($store, $subscriber, $cfg);
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{ok: bool, message: string, coupon_code?: string|null, view?: array<string, mixed>}
     */
    protected function confirmSubscriber(Store $store, NewsletterSubscriber $subscriber, array $cfg): array
    {
        if (! $subscriber->coupon_id) {
            $coupon = $this->issueCoupon($store, $subscriber, $cfg);
            $subscriber->coupon_id = $coupon->id;
            $subscriber->coupon_code = $coupon->code;
        }

        $subscriber->status = 'confirmed';
        $subscriber->confirmed_at = now();
        $subscriber->confirm_token = null;
        $subscriber->save();

        $coupon = $subscriber->coupon_id
            ? Coupon::query()->find($subscriber->coupon_id)
            : null;
        $expiresLabel = $coupon?->ends_at
            ? $coupon->ends_at->timezone(config('app.timezone'))->format('d/m/Y')
            : 'próximos '.$cfg['coupon_days'].' días';

        try {
            Mail::to($subscriber->email)->send(new NewsletterCouponMail(
                $store,
                (string) $subscriber->coupon_code,
                $cfg['coupon_hint'],
                $expiresLabel,
                route('store.design.show', $store->slug)
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'ok' => true,
            'message' => '¡Listo! Te enviamos tu cupón por correo.',
            'coupon_code' => $subscriber->coupon_code,
            'view' => [
                'store' => $store,
                'coupon_code' => $subscriber->coupon_code,
                'coupon_hint' => $cfg['coupon_hint'],
                'days' => $cfg['coupon_days'],
                'expires' => $expiresLabel,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    protected function issueCoupon(Store $store, NewsletterSubscriber $subscriber, array $cfg): Coupon
    {
        $code = $this->uniqueCode($store, $cfg['coupon_prefix']);

        return Coupon::create([
            'store_id' => $store->id,
            'code' => $code,
            'type' => $cfg['coupon_type'],
            'value' => $cfg['coupon_value'],
            'min_subtotal' => 0,
            'max_redemptions' => 1,
            'redemptions_count' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addDays((int) $cfg['coupon_days'])->endOfDay(),
            'is_active' => true,
        ]);
    }

    protected function uniqueCode(Store $store, string $prefix): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = strtoupper($prefix.'-'.Str::upper(Str::random(6)));
            $exists = Coupon::query()
                ->where('store_id', $store->id)
                ->where('code', $code)
                ->exists();
            if (! $exists) {
                return $code;
            }
        }

        return strtoupper($prefix.'-'.Str::upper(Str::random(10)));
    }
}
