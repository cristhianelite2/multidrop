# Changelog

Todos los cambios relevantes de Multidrop se documentan aquí.

## 2026-08-31

### Marketing
- Pestaña **Publicaciones** en campañas con iframe embebido de Seller Central.
- URL del embed configurable por tienda (`settings.marketing.sellercentral_embed_url`) o global (`SELLERCENTRAL_EMBED_URL`).

### Catálogo admin
- Icono de ojo en listado de productos para abrir la ficha en la tienda (nueva pestaña).

## 2026-08-29

### Product Hunter / AliExpress
- Importación por URL, HTML pegado y extensión Chrome (`aliexpress-hunter`).
- Extracción de variantes desde DOM (`data-sku-col` / imágenes SKU), no solo la opción seleccionada.
- URL de origen canónica `/item/{id}.html` (ignora trackers tipo Criteo).
- Descripción en texto plano con espacios colapsados al importar y al guardar.
- UI: colapsar/expandir detalles, descripción y reseñas; miniaturas de variantes con `referrerpolicy`.
- Extensión 1.0.2: valida token, selector de tienda, captura directa a borrador (sin abrir Hunter).

### Admin
- Perfil: botones Guardar/Contraseña dentro de la tarjeta (sin barra fija).

### Catálogo admin
- Origen AE vs CJ en listado y ficha; reseñas/detalles/rating editables.
- Sync AE guarda reviews, details y rating en `verified_data`.

### Storefront
- Lightbox modal al clic en fotos de reseñas/comentarios (flechas y Esc).

### Infra / config
- Cloudflare Browser Rendering y ajustes de settings generales para scraping AE.

## 2026-08-27

### Marketing
- Workspace de campaña con pestañas (Resumen, Videos, Prompts, Resultados, Optimizar).
- Videos anidados en cada campaña; la biblioteca suelta deja de ser el centro.
- Duplicar campañas (prompts, videos y target; sin copiar gasto ni payload de ads).
- Brief JSON y webhook opcional (`MARKETING_OPTIMIZER_WEBHOOK`) para targets y presupuesto.

### Newsletter
- Listado de confirmados primero, con filtros, búsqueda, paginación y export CSV.
- Configuración de popup y cupón en una pestaña aparte.

### Admin
- El botón Guardar ya no arrastra tarjetas enteras a la barra fija (formularios en pestañas y ruleta).

### Infra
- Subdominio público `https://shop.ceballosleon.com` en el droplet (Apache + túnel Cloudflare).
