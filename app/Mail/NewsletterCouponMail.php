<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterCouponMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public string $couponCode,
        public string $couponHint,
        public string $expiresLabel,
        public ?string $shopUrl = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cupón '.$this->couponCode.' — '.$this->store->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody(),
        );
    }

    protected function htmlBody(): string
    {
        $name = e($this->store->name);
        $code = e($this->couponCode);
        $hint = e($this->couponHint);
        $exp = e($this->expiresLabel);
        $shop = $this->shopUrl ? e($this->shopUrl) : '';
        $shopBtn = $shop !== ''
            ? '<p style="margin:24px 0"><a href="'.$shop.'" style="display:inline-block;background:#f59e0b;color:#0f172a;text-decoration:none;padding:12px 20px;border-radius:999px;font-weight:800">Ir a la tienda</a></p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="es"><body style="font-family:system-ui,sans-serif;line-height:1.5;color:#0f172a;padding:24px">
  <h2 style="margin:0 0 12px">¡Tu cupón está listo!</h2>
  <p>Bienvenido a <strong>{$name}</strong>. Aquí tienes tu cupón personalizado:</p>
  <p style="font-size:28px;font-weight:800;letter-spacing:.06em;margin:16px 0;padding:14px 18px;background:#f1f5f9;border-radius:12px;display:inline-block">{$code}</p>
  <p><strong>{$hint}</strong> · Válido hasta <strong>{$exp}</strong>.</p>
  {$shopBtn}
  <p style="font-size:13px;color:#64748b">Úsalo en tu próxima compra. Es personal y de un solo uso.</p>
</body></html>
HTML;
    }
}
