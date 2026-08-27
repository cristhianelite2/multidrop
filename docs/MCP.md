# MCP de catálogo Multidrop (solo lectura)

Capa de herramientas para que una IA consulte productos de la plataforma **sin modificar nada**.

```
IA  →  MCP (stdio)  →  ProductCatalogService  →  modelo Product  →  base de datos
```

No accede a clientes, pedidos ni credenciales. No hay herramientas de escritura.

## Cómo iniciarlo

Desde la raíz del proyecto:

```bash
php artisan mcp:serve
```

El proceso habla JSON-RPC 2.0 por STDIN/STDOUT (protocolo MCP). Déjalo corriendo; el cliente MCP lo lanza solo.

## Variables de entorno

En `.env` (nunca en el frontend):

| Variable | Obligatorio | Uso |
|---|---|---|
| `MCP_API_KEY` | No para stdio | Reservada si más adelante se expone un endpoint HTTP interno. No se inyecta a Blade ni a JS. |
| `APP_URL` | Sí para URLs públicas | Se usa al armar `public_url` del producto. |
| `DB_*` | Sí | Las mismas credenciales que ya usa Laravel. |

No hace falta ninguna key extra para el modo stdio local: el proceso corre en el servidor con el `.env` de Laravel.

## Conectarlo a un cliente MCP (Cursor)

En `.cursor/mcp.json` del proyecto (o en la config MCP del cliente):

```json
{
  "mcpServers": {
    "multidrop-catalog": {
      "command": "php",
      "args": ["artisan", "mcp:serve"],
      "cwd": "F:/xampp82/htdocs/html/multidrop"
    }
  }
}
```

Ajusta `cwd` a la ruta real del proyecto. Reinicia el cliente MCP (en Cursor: recargar MCP) después de guardar.

Si PHP no está en el PATH, usa la ruta absoluta, por ejemplo `"C:/xampp82/php/php.exe"`.

## Herramientas

### `search_products`

Busca por nombre (también SKU y slug).

Argumentos:

- `query` (string, obligatorio)
- `limit` (integer, opcional, 1–20, default 10)

### `get_product`

Consulta un producto por ID.

Argumentos:

- `product_id` (integer, obligatorio, ≥ 1)

### Campos devueltos (solo si existen en BD)

`id`, `name`, `description`, `sale_price` + `currency`, `supplier_cost` + `supplier_cost_currency`, `sku`, `stock`, `category`, `public_url`, `image_url`, `supplier_name`, más `store_id` / `store_name` / `store_slug` y `status` si hay tienda asociada.

No se inventan valores: si un campo no está en la base, no aparece.

## Ejemplos

### search_products

Petición MCP (`tools/call`):

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "search_products",
    "arguments": {
      "query": "power bank",
      "limit": 5
    }
  }
}
```

Respuesta típica (contenido de texto JSON):

```json
[
  {
    "id": 37,
    "name": "Power Bank 20000mAh",
    "sale_price": 499,
    "currency": "MXN",
    "sku": "CJ-123",
    "stock": 80,
    "public_url": "http://127.0.0.1:8003/s/emergency-power/pages/power-bank-20000mah",
    "image_url": "https://…",
    "supplier_name": "CJ Dropshipping",
    "status": "live"
  }
]
```

### get_product

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "get_product",
    "arguments": {
      "product_id": 37
    }
  }
}
```

Si el ID no existe, el contenido indica `Producto no encontrado`.
