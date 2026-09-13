# Emergency Power — Multidrop theme

Plantilla multi-página estilo Shopify para la tienda **Emergency Power** (slug: `emergency-power`).

## Identidad visual
"Placa de equipo de emergencia": panel grafito (`#14181A`), ámbar indicador (`#F2A93B`) como acento
principal, rojo señal (`#E5573F`) para badges de urgencia/stock bajo, verde carga (`#4FB286`) para
estados OK. Tipografía: **Barlow Condensed** (títulos, mayúsculas, condensada — placa técnica),
**Inter** (cuerpo), **JetBrains Mono** (precios, specs, UI de datos). Elemento firma: el **Power
Gauge**, un dial radial que aparece en el hero y se repite en miniatura como barra de carga en las
tarjetas de producto.

## Archivos
| Página     | Handle     | Archivos                                  |
|------------|------------|--------------------------------------------|
| Landing    | `index`    | `index.html`, `index.css`, `index.js`      |
| Catálogo   | `catalog`  | `catalog.html`, `catalog.css`, `catalog.js`|
| Producto   | `product`  | `product.html`, `product.css`, `product.js`|
| Carrito    | `cart`     | `cart.html`, `cart.css`                    |
| Checkout   | `checkout` | `checkout.html`, `checkout.css`, `checkout.js` |
| Página libre | `page`   | `page.html`, `page.css`                    |
| Globales   | —          | `theme.css`, `theme.js`                    |
| Assets     | —          | `assets/*.svg` (logo + iconos + patrón de fondo) |

## Subida
1. **Admin → Diseño → Assets**: subir todo el contenido de `/assets`.
2. Subir `theme.css` y `theme.js` como globales.
3. Crear/editar cada página con su handle exacto (`index`, `catalog`, `product`, `cart`,
   `checkout`, y páginas libres con el handle que corresponda) y pegar su HTML/CSS/JS.
4. Verificar reemplazo de tokens `{{store.*}}`, `{{products.count}}`, `{{urls.*}}` y, en `page.html`,
   `{{page.title}}` / `{{page.content}}`.
5. Previsualizar en `/admin/store/design/preview?page={id}` antes de publicar (la página *Inicio*
   está en `draft`).

## Runtime
Todas las páginas cargan `theme.js` (que espera `window.Multidrop = { store, products, product,
cart, page, checkout, urls, csrf }`) antes de su script específico. `theme.js` expone
`Multidrop.Theme` (tarjeta de producto, formateo de precio, estado vacío) y `Multidrop.Cart` para
que los scripts de página reutilicen la misma lógica sin duplicarla (p. ej. `catalog.js` reordena
usando `Multidrop.Theme.buildProductCard`; `checkout.js` lee el resumen con `Multidrop.Cart`).

Si `Multidrop.api.addToCart` existe, el carrito lo usa; si no, cae a `localStorage` por tienda —
así las plantillas funcionan también en preview standalone.

## Hooks usados
- `[data-md-products]` (+ `data-md-limit`, `data-md-featured`, `data-md-manual`)
- `[data-md-bind="name|price_formatted|image|badge|description|<campo-custom>"]`
- `[data-md-product]`, `[data-md-cart]`, `[data-md-add-to-cart]`, `[data-md-qty]`
- `--md-checkout-primary|accent|button|bg|text` en `checkout.css`/`theme.css`

## Pendiente de verificación en dispositivo real
- [ ] Assets subidos y visibles
- [ ] Prueba en móvil (checklist original del brief)
- [ ] Confirmar que el mount point `[data-md-checkout-gateway]` recibe la pasarela real
