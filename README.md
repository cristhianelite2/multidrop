# Multidrop

Plataforma interna de experimentación e-commerce (Product Lab). **No es la tienda pública.**

La cara al cliente es una **mega-tienda** con **mini-tiendas** por sector, idioma y necesidad.

## Stack

- Laravel 11 + Blade + jQuery
- MySQL (`multidrop`)
- Queues (database)
- IA: OpenAI (ChatGPT) + [ia.ceballosleon.com](https://ia.ceballosleon.com) (MIIA)
- Supplier: CJ Dropshipping API v2
- Pagos: Mercado Pago (default), Stripe, PayPal

## Setup local (XAMPP)

1. DB ya creada: `multidrop`
2. Copia/ajusta `.env` (keys IA, CJ, pagos)
3. `php artisan migrate --seed`
4. Abre `http://localhost/html/multidrop/public`
5. Lab: `http://localhost/html/multidrop/public/admin/lab`

## Variables clave

```
AI_DEFAULT_PROVIDER=miia
MIIA_API_KEY=mia_...
MIIA_BASE_URL=https://ia.ceballosleon.com
OPENAI_API_KEY=sk-...
CJ_EMAIL=...
CJ_API_KEY=...
MERCADOPAGO_ACCESS_TOKEN=...
```

## Arquitectura Domain

- `App\Domain\AI` — dual provider + fallback
- `App\Domain\Suppliers` — `SupplierInterface` + `CjConnector`
- `App\Domain\Payments` — Stripe / PayPal / Mercado Pago
- `App\Domain\Discovery` — problema → keywords IA → candidatos CJ
- `App\Domain\Scoring` — Product Score 0–100
