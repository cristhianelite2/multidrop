---
name: laravel
description: Develop and maintain this Laravel 11 Multidrop application using its domain, multi-tenant, authorization, Blade, and testing conventions.
---

# Laravel development for Multidrop

Use this skill when changing PHP, Laravel routes, controllers, models, migrations, policies, Blade views, jobs, or feature tests in this repository.

## Project baseline

- Target PHP 8.2+ and Laravel 11.
- Use MySQL through the XAMPP environment; do not change Apache configuration.
- Keep domain logic in the existing `App\Domain\*` areas when the change belongs to AI, suppliers, payments, discovery, or scoring.
- Follow the existing controller organization:
  - `Admin` for authenticated administration
  - `Buyer` for buyer accounts
  - `Storefront` for public store experiences
- Keep translations in `lang/{es,en,pt}` rather than adding user-facing strings directly to views or controllers.

## Routing and authorization

- Name every route and generate links with `route()` or `to_route()`; do not hardcode application URLs.
- Protect administrative routes with the applicable `auth`, `admin.active`, `admin.store`, and `permission:*` middleware.
- Treat admin users and buyer accounts as separate authentication concerns.
- For a new admin capability, verify the permission middleware and seed the permission through the existing access seeder pattern.
- Use route model binding where it preserves the existing authorization and tenant checks; do not rely on an unscoped ID from request input.

```php
Route::middleware(['auth', 'admin.active', 'permission:products.view'])
    ->get('/admin/products', [ProductController::class, 'index'])
    ->name('admin.products.index');
```

## Multi-tenant data access

- Scope store-owned reads, writes, updates, and deletes by `store_id`.
- Derive the active store from the authenticated/admin context or the authorized route binding, not from a freely supplied hidden form field.
- Apply tenant scoping before mutations and before returning resources or views.
- When adding relationships or queries involving `Store`, check whether both principal and mini stores are valid for the operation.
- Preserve soft deletes on important business models such as products and orders; use `withTrashed()` only when the use case explicitly requires it.

```php
$product = Product::query()
    ->where('store_id', $store->id)
    ->whereKey($productId)
    ->firstOrFail();
```

## Application code

- Validate request data with a Form Request when validation is non-trivial or reused. Authorize the request explicitly.
- Prefer injected services and existing domain interfaces over new API calls or duplicated business logic in controllers.
- Use transactions for operations that update multiple related records or affect inventory/order state.
- Make queue jobs safe to retry and avoid serializing request objects or unnecessary models.
- Return repository-standard validation errors, redirects, notifications, and logs; do not silently swallow exceptions or return success-shaped fallbacks.
- Use Eloquent relationships and casts consistently with the model. Add migrations for schema changes and update the relevant model/factory/seeder when needed.

## Blade and frontend

- Reuse existing Blade layouts, components, and route names before introducing new markup.
- Escape user-controlled output by default; only render trusted HTML through the repository's established convention.
- Preserve the existing Blade + jQuery + Vite stack and avoid introducing a frontend framework for an isolated change.
- Keep tenant/store context visible in admin forms and ensure forms include CSRF protection and method spoofing where required.

## Tests and validation

- Add or update a focused feature/unit test for behavior changes, especially authorization, tenant isolation, validation, and payment/webhook flows.
- Prefer Laravel's HTTP and database testing helpers over testing implementation details.
- Run the smallest relevant checks first:

```bash
php artisan test --filter=RelevantTestName
php artisan pint --test
php artisan route:list
```

- For migration or seed changes, validate with the repository's configured database and the applicable `php artisan migrate`/seeder command.
- Before finishing, inspect route names, middleware, tenant filters, soft-delete behavior, and translated strings affected by the change.

## Common examples

### Correct: tenant-scoped mutation

```php
$order = Order::query()
    ->where('store_id', $store->id)
    ->whereKey($request->integer('order'))
    ->firstOrFail();

$order->update($validated);
```

### Avoid: trusting an unscoped request ID

```php
$order = Order::findOrFail($request->input('order'));
$order->update($validated);
```

### Correct: named route in a view

```blade
<a href="{{ route('storefront.product.show', ['store' => $store, 'product' => $product]) }}">
    {{ $product->name }}
</a>
```

### Avoid: hardcoded application paths

```blade
<a href="/s/{{ $store->slug }}/products/{{ $product->id }}">...</a>
```
