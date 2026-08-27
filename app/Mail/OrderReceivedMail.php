<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Store;
use App\Services\Platform\PlatformContact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public Order $order,
        public string $trackUrl,
        public string $portalUrl = ''
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Gracias por tu pedido '.$this->order->number.' — '.$this->store->name,
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
        $store = e($this->store->name);
        $number = e($this->order->number);
        $name = e($this->order->customer_name ?: 'hola');
        $track = e($this->trackUrl);
        $portal = e($this->portalUrl);
        $total = e(number_format((float) $this->order->total, 2).' '.$this->order->currency);

        $contact = app(PlatformContact::class)->all();
        $contactHtml = '';
        if (app(PlatformContact::class)->hasAny()) {
            $rows = [];
            if (! empty($contact['email'])) {
                $rows[] = 'Email: <a href="mailto:'.e($contact['email']).'">'.e($contact['email']).'</a>';
            }
            if (! empty($contact['phone'])) {
                $rows[] = 'Teléfono: '.e($contact['phone']);
            }
            if (! empty($contact['whatsapp'])) {
                $wa = e(app(PlatformContact::class)->whatsappUrl() ?? '');
                $rows[] = 'WhatsApp: <a href="'.$wa.'">'.e($contact['whatsapp']).'</a>';
            }
            if (! empty($contact['hours'])) {
                $rows[] = 'Horario: '.e($contact['hours']);
            }
            if (! empty($contact['note'])) {
                $rows[] = e($contact['note']);
            }
            $contactHtml = '<div style="margin-top:28px;padding:16px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0">'
                .'<p style="margin:0 0 8px;font-weight:700">¿Necesitas ayuda?</p>'
                .'<p style="margin:0;font-size:14px;color:#334155">'.implode('<br>', $rows).'</p>'
                .'</div>';
        }

        $portalBlock = $portal !== ''
            ? '<p style="margin:16px 0 0"><a href="'.$portal.'" style="color:#0f766e;font-weight:600">Abrir mi cuenta de comprador</a></p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="es"><body style="font-family:system-ui,sans-serif;line-height:1.55;color:#0f172a;padding:24px;background:#f8fafc">
  <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px">
    <p style="margin:0 0 4px;font-size:13px;color:#64748b;letter-spacing:.04em;text-transform:uppercase">{$store}</p>
    <h1 style="margin:0 0 12px;font-size:1.45rem">¡Gracias, {$name}!</h1>
    <p>Recibimos tu pedido <strong>{$number}</strong> (total {$total}). Ya estamos trabajando en él.</p>
    <p>Te enviaremos actualizaciones por correo con indicaciones, estado del envío y cómo darle seguimiento en cualquier momento.</p>
    <p style="margin:24px 0">
      <a href="{$track}" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:12px 20px;border-radius:999px;font-weight:700">
        Seguir mi pedido
      </a>
    </p>
    {$portalBlock}
    {$contactHtml}
    <p style="margin:24px 0 0;font-size:12px;color:#94a3b8">Guarda este correo. Con tu número de pedido y email puedes consultar el estado cuando quieras.</p>
  </div>
</body></html>
HTML;
    }
}
