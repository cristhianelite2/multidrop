# Changelog

Todos los cambios relevantes de Multidrop se documentan aquí.

## 2026-09-01

### Marketing
- **Prompts con IA por producto:** en campaña → pestaña Prompts, selecciona un producto y MIIA analiza nombre, imágenes, videos y reseñas para generar un guion TikTok en segmentos de máx. 3 segundos (hook → CTA).
- El prompt se puede guardar en la campaña o enviar directo a **Creatify** con las imágenes y videos del producto (`link_with_params` + `override_script`).
- `marketing_prompts` guarda `product_id`, `segments` y `analysis` para reutilizar y ajustar duración del video en Creatify.
- API `prompts/catalog/products.json` para buscar productos en el generador; script `scripts/deploy-shop.sh` para desplegar en el droplet.

### Storefront
- **Upsell overlay:** se sanitiza CSS de tema que rompía el layout (`display:flex` horizontal en `.md-mod-upsell`) y se añade capa guard de plataforma para overlays inyectados.
- **Galería admin:** fix clic en «Quitar» y acciones del menú ⋯ cuando el panel flotante está en `body`; confirmación antes de quitar imagen o video; eliminación inmediata en BD y vista; borrado de R2 en segundo plano (job `afterResponse`) sin recalcular todo el bucket.
- **Precio de vitrina:** si el precio guardado coincide con el de compra (importación marketplace), el storefront y el checkout calculan automáticamente precio de venta + compare tachado (fees + margen + charm pricing).
- Importación AliExpress ya no copia el precio del marketplace como precio de venta; solo rellena `purchase_price`.

### Plataforma
- Integración **Cloudflare R2** para imágenes y videos de productos.
- Configuración en General de plataforma (bucket, API keys S3, prueba de conexión).
- URLs enmascaradas `/{f}/stores/{tienda}/products/{id}/…` servidas vía proxy Laravel.
- Monitoreo de almacenamiento R2 por tienda (recalcular desde bucket).
- Al importar producto (CJ, AliExpress o manual) se copia media a R2 automáticamente.
- **Fix prueba R2:** SDK S3 directo, reintento path-style/virtual-hosted, credenciales desde BD sin sobrescribir `********`, mensajes AWS claros.
- **Fix UI:** un solo mensaje al probar APIs (R2, CJ, AliExpress, Cloudflare) sin toast duplicado.
- **Extensión AliExpress:** al capturar ficha → borrador, copia imágenes/videos a R2 (con Referer AE, reseñas y descripción HTML).
- Respuesta del plugin indica cuántos archivos se copiaron a R2.

### Admin tienda
- Campo **Correo de la cuenta** en General de la tienda (`settings.contact.email`).

### Catálogo admin
- Campo **Precio de compra** en productos (`purchase_price`), rellenado al importar desde CJ o AliExpress según el precio del marketplace.
- Botón ✨ junto al nombre para **acortar el título con IA** (MIIA).
- **Sugerir precios IA** calcula el precio de venta desde el precio de compra + fees + margen objetivo.
- El desglose de márgenes usa el precio de compra guardado o el del marketplace.
- Sanitización del nombre acortado por IA (sin anotaciones tipo «70 caracteres»).
- Spinner de carga en el botón ✨ de acortar nombre.
- Sección **Imágenes y videos** editable en la ficha de producto (CJ, AliExpress y manual).
- Subida de imágenes y videos por archivo en la galería del producto.
- Rutas de media visibles en la galería con botones **Copiar ruta** y **Copiar URL** (R2 `/f/…`, storage local o URL externa).
- **Importar de producto similar** en edición: URL de AliExpress o CJ → imágenes, videos, reseñas, descripción y detalles (añadir o reemplazar), con vista previa y copia a R2.
- **Descargar media** en edición de producto: botón por imagen/video y **Descargar ZIP** para galería (R2 con `?download=1`, externas vía proxy).
- **Extensión Hunter 1.0.4:** extraer secciones de ficha AE/CJ a producto existente por SKU (buscar destino, elegir secciones, importar desde página activa).
- Panel **Extraer al producto** comprimido en el popup (acordeón expandible, resumen del destino, estado persistido).
- Tarjeta de **producto destino** en el plugin con miniatura, nombre destacado e ID/SKU visibles (v1.0.5).
- **Fix imagen destino** en plugin: API resuelve miniatura desde galería/verified_data y URL absoluta; el popup reconsulta si faltan datos guardados (v1.0.6).
- **Fix extracción de videos** en plugin: evita fallo al espejar a R2 durante extract, prefiere MP4 sobre M3U8 y mensajes de error más claros (v1.0.7).
- **Fix timeout al extraer videos** (v1.0.8): snapshot compacto, detección de video en página, ruta rápida sin enrich remoto y keep-alive del service worker.
- **Fix message port closed** (v1.0.9): no serializar runParams completo; lectura rápida en background y fetch de extract desde el popup; snapshot-only para videos.
- **Fix extract solo videos:** sin error `shipping_price` ni lógica de envío/descripción al importar únicamente videos o imágenes.
- **Extensión Hunter 1.1.1:** menú contextual resuelve thumb del carrusel AE/CJ a imagen grande (`imagePathList` / visor principal) antes de importar.
- **Galería admin:** reordenar y quitar imágenes y videos con menú ⋯ (popover), arrastre ⋮⋮, copiar ruta/URL y descargar sin saturar la tarjeta.
- Endpoint `plugin-import-image` para importar una sola imagen desde URL al producto seleccionado en el plugin.

## 2026-08-31

### Marketing
- Pestaña **Publicaciones** en campañas con iframe embebido de Seller Central.
- URL del embed configurable por tienda (`settings.marketing.sellercentral_embed_url`) o global (`SELLERCENTRAL_EMBED_URL`).

### Catálogo admin
- Icono de ojo en listado de productos para abrir la ficha en la tienda (nueva pestaña).

### Plantillas
- Botón **Descargar ZIP** en la biblioteca de plantillas (exporta `theme.css`, `modules.css`, `layout.json`, `assets/` y `pages/*.twig`).

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
