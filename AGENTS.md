# Multidrop - Guía del Proyecto

## Stack Tecnológico
- **Framework**: Laravel 11
- **PHP**: 8.2+
- **Base de datos**: MySQL (XAMPP)
- **Frontend**: Blade templates, Vite
- **Servidor local**: Apache (XAMPP) - NO tocar configuración de Apache

## Arquitectura del Proyecto

### Estructura de Controladores
```
app/Http/Controllers/
├── Admin/                    # Panel de administración
│   ├── Store/               # Gestión de tiendas
│   │   ├── Marketing/       # Campañas, videos, prompts
│   │   ├── ProductController.php
│   │   ├── OrderController.php
│   │   └── DesignController.php
│   ├── AuthController.php
│   └── DashboardController.php
├── Buyer/                    # Portal de compradores
├── Storefront/               # Tienda pública
│   ├── CartController.php
│   ├── CheckoutController.php
│   └── StoreController.php
└── MediaFileController.php
```

### Modelos Principales
- `Store` - Tiendas (tipos: principal, mini)
- `Product` - Productos con variantes
- `Order` - Órdenes de compra
- `Customer` - Clientes
- `Theme` / `StoreDesign` - Diseños de tienda
- `MarketingCampaign` - Campañas de marketing
- `BuyerAccount` - Cuentas de compradores

### Convenciones de Rutas
- `/admin/*` - Panel de administración (requiere auth + permisos)
- `/s/{slug}/` - Storefront por tienda
- `/cuenta/*` - Portal de compradores
- `/t/{theme:slug}/` - Sandbox de temas
- `/webhooks/*` - Webhooks de pagos (MercadoPago, PayPal, Stripe)

### Autenticación y Permisos
- Middlewares: `auth`, `admin.active`, `admin.store`, `permission:xxx`
- Sistema de roles y permisos custom
- Autenticación separada: admin users vs buyer accounts

## Comandos Útiles
```bash
# Migraciones
php artisan migrate
php artisan migrate:fresh --seed

# Seeders específicos
php artisan db:seed --class=DatabaseSeeder
php artisan db:seed --class=AdminAccessSeeder

# Cache
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Desarrollo
php artisan serve
npm run dev
```

## Configuración del Proyecto
- Archivos de config en `config/`: `multidrop.php`, `payments.php`, `shipping.php`, `ai.php`
- Variables de entorno en `.env`
- Lang files en `lang/{es,en,pt}/`

## Reglas Importantes
1. **NO tocar Apache** - El servidor ya está configurado en XAMPP
2. **Usar rutas con names** - Siempre usar `route('name')` en lugar de URLs hardcodeadas
3. **Middleware de permisos** - Verificar permisos antes de crear rutas admin
4. **Multi-tenant** - Muchas operaciones filtran por `store_id`
5. **Soft deletes** - Usar en modelos importantes (Product, Order, etc.)

## Archivos de Configuración por Defecto
```php
// config/multidrop.php - Configuración general de la plataforma
// config/payments.php - Métodos de pago habilitados
// config/shipping.php - Zonas y métodos de envío
// config/ai.php - Configuración de IA (Creatify, Remotion, etc.)
```
