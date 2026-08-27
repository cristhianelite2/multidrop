# Changelog

Todos los cambios relevantes de Multidrop se documentan aquí.

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
