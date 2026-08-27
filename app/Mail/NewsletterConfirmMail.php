<?php

namespace App\Mail;

use App\Models\NewsletterSubscriber;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public NewsletterSubscriber $subscriber,
        public string $confirmUrl,
        public string $couponHint
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirma tu suscripción — '.$this->store->name,
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
        $url = e($this->confirmUrl);
        $hint = e($this->couponHint);

        return <<<HTML
<!DOCTYPE html>
<html lang="es"><body style="font-family:system-ui,sans-serif;line-height:1.5;color:#0f172a;padding:24px">
  <h2 style="margin:0 0 12px">Confirma tu correo</h2>
  <p>Gracias por unirte a <strong>{$name}</strong>.</p>
  <p>Al confirmar recibirás un cupón personalizado: <strong>{$hint}</strong>.</p>
  <p style="margin:24px 0">
    <a href="{$url}" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:12px 20px;border-radius:999px;font-weight:700">
      Confirmar y obtener cupón
    </a>
  </p>
  <p style="font-size:13px;color:#64748b">Si no solicitaste esto, ignora este mensaje.</p>
</body></html>
HTML;
    }
}
