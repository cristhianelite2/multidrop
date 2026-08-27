<?php

namespace App\Services\Storefront;

/**
 * Localiza copy estático de plantillas EN cuando la tienda está en otro idioma.
 * No toca bindings {{...}} ni atributos data-md-*.
 */
class DesignCopyLocalizer
{
    /**
     * Localiza un título de página (string corto completo).
     */
    public function localizeTitle(string $title, string $locale): string
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        $short = explode('_', $locale)[0] ?: 'es';
        if ($short !== 'es') {
            return $title;
        }

        $map = [
            'Home' => 'Inicio',
            'Index' => 'Inicio',
            'Shop' => 'Tienda',
            'Catalog' => 'Catálogo',
            'Product' => 'Producto',
            'Cart' => 'Carrito',
            'Your cart' => 'Tu carrito',
            'Checkout' => 'Checkout',
            'About' => 'Nosotros',
            'FAQ' => 'Preguntas',
            'Contact' => 'Contacto',
            'Page' => 'Página',
        ];

        $trim = trim($title);
        return $map[$trim] ?? $this->localize($title, $locale);
    }

    /**
     * @param  array<string, mixed>|null  $design
     */
    public function localize(string $content, string $locale, ?array $design = null): string
    {
        if ($content === '') {
            return $content;
        }

        $locale = strtolower(str_replace('-', '_', trim($locale)));
        $short = explode('_', $locale)[0] ?: 'es';

        if ($short === 'en') {
            return $content;
        }

        if ($short === 'es') {
            return $this->applyMap($content, $this->enToEs());
        }

        return $content;
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function applyMap(string $content, array $map): string
    {
        // Frases largas primero para no romper substrings.
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_replace(array_keys($map), array_values($map), $content);
    }

    /**
     * @return array<string, string>
     */
    protected function enToEs(): array
    {
        return [
            // html lang
            'lang="en"' => 'lang="es"',
            "lang='en'" => "lang='es'",

            // Page titles exactos (solo cuando el string completo es el título)
            // — no mapear palabras sueltas aquí; se hace en localizeTitle()

            // Nav / chrome
            '>Home<' => '>Inicio<',
            '>Shop<' => '>Tienda<',
            '>About<' => '>Nosotros<',
            '>FAQ<' => '>Preguntas<',
            '>Cart<' => '>Carrito<',
            '>Contact<' => '>Contacto<',
            '>Support<' => '>Soporte<',
            '>Company<' => '>Empresa<',
            '>All products<' => '>Todos los productos<',
            '>Shipping &amp; returns<' => '>Envíos y devoluciones<',
            '>Shipping & returns<' => '>Envíos y devoluciones<',
            'Open menu' => 'Abrir menú',
            'aria-label="Decrease quantity"' => 'aria-label="Disminuir cantidad"',
            'aria-label="Increase quantity"' => 'aria-label="Aumentar cantidad"',
            'aria-label="Quantity"' => 'aria-label="Cantidad"',
            'aria-label="Remove item"' => 'aria-label="Quitar producto"',

            // Cart
            'Your cart —' => 'Tu carrito —',
            'Your cart is empty' => 'Tu carrito está vacío',
            'Your cart' => 'Tu carrito',
            'Kit review' => 'Revisa tu kit',
            'Add a power station or two before the next outage.' => 'Agrega una estación de energía antes del próximo apagón.',
            'Order summary' => 'Resumen del pedido',
            'Calculated at checkout' => 'Se calcula en el checkout',
            'Checkout →' => 'Ir al checkout →',
            'Shop backup power →' => 'Ver energía de respaldo →',

            // Checkout
            'Secure checkout' => 'Checkout seguro',
            'Information &amp; payment' => 'Datos y pago',
            'Information & payment' => 'Datos y pago',
            'Confirmation' => 'Confirmación',
            '>Contact<' => '>Contacto<',
            'Shipping address' => 'Dirección de envío',
            'First name' => 'Nombre',
            'Last name' => 'Apellido',
            '>Address<' => '>Dirección<',
            '>City<' => '>Ciudad<',
            'ZIP / Postal code' => 'C.P. / Código postal',
            '>Payment<' => '>Pago<',
            'Your cart is empty — <a href="' => 'Tu carrito está vacío — <a href="',
            'go pick something out' => 'ver el catálogo',

            // Catalog
            'Shop all backup power —' => 'Toda la energía de respaldo —',
            'Shop all backup power' => 'Toda la energía de respaldo',
            'Full catalog' => 'Catálogo completo',
            '>Sort<' => '>Ordenar<',
            '>Featured<' => '>Destacados<',
            'Price: low to high' => 'Precio: menor a mayor',
            'Price: high to low' => 'Precio: mayor a menor',
            'Name: A–Z' => 'Nombre: A–Z',
            'Name: A-Z' => 'Nombre: A-Z',

            // Product
            'Add to cart' => 'Agregar al carrito',
            'Add to Cart' => 'Agregar al carrito',
            'Product name' => 'Nombre del producto',
            'Product description goes here.' => 'Aquí va la descripción del producto.',
            '>Videos<' => '>Videos<',
            '>Capacity<' => '>Capacidad<',
            '>Output<' => '>Potencia<',
            '>Recharge<' => '>Recarga<',
            'Pairs well' => 'Combina bien',
            'You may also like' => 'También te puede gustar',
            '2-year warranty on cells &amp; electronics' => 'Garantía de 2 años en celdas y electrónica',
            '2-year warranty on cells & electronics' => 'Garantía de 2 años en celdas y electrónica',
            'Ships within 24 hours' => 'Envío en menos de 24 horas',
            'No products yet' => 'Aún no hay productos',
            'Add products in the store admin to fill this grid.' => 'Agrega productos en el admin para llenar esta cuadrícula.',

            // Landing / marketing (Emergency Power theme)
            'Power that doesn\'t quit when the grid does' => 'Energía que no se apaga cuando falla la red',
            'Power that<br>doesn\'t quit<br>when the grid does.' => 'Energía que<br>no se apaga<br>cuando falla la red.',
            'Backup power stations, generators and batteries for outages, storms and off-grid life. Shop {{store.name}}.' => 'Estaciones de energía, generadores y baterías para apagones, tormentas y vida off-grid. Compra en {{store.name}}.',
            'Be ready before it goes dark' => 'Prepárate antes de que se vaya la luz',
            'Portable power stations, solar generators and batteries chosen for one job: keeping your fridge, medical gear and lights running through the next outage.' => 'Estaciones portátiles, generadores solares y baterías pensadas para un solo trabajo: mantener tu refrigerador, equipo médico y luces encendidas en el próximo apagón.',
            'How we test gear' => 'Cómo probamos el equipo',
            'Typical backup runtime, 72 hours on a full charge' => 'Autonomía típica de respaldo: 72 horas con carga completa',
            'Hrs backup, avg. unit' => 'Hrs de respaldo, promedio',
            'Peak output' => 'Potencia pico',
            'Weather sealed' => 'Sellado contra clima',
            'Ships same day' => 'Envío el mismo día',
            'Units in stock' => 'Unidades en stock',
            'Ready stock' => 'Listo para enviar',
            'Grab-and-go power' => 'Energía lista para llevar',
            'View full catalog →' => 'Ver catálogo completo →',
            'Real runtime numbers' => 'Horas de respaldo reales',
            'Every listing states tested backup hours, not lab-only peak specs.' => 'Cada ficha muestra horas de respaldo probadas, no solo picos de laboratorio.',
            '2-year warranty' => 'Garantía de 2 años',
            'Full coverage on cells and electronics, no fine-print exceptions.' => 'Cobertura total en celdas y electrónica, sin letra chica.',
            'Fast, insured shipping' => 'Envío rápido y asegurado',
            'Same-day dispatch on in-stock units, tracked door to door.' => 'Despacho el mismo día en unidades disponibles, con seguimiento puerta a puerta.',
            'Storm season doesn\'t wait' => 'La temporada de tormentas no espera',
            'Build your kit before the forecast changes.' => 'Arma tu kit antes de que cambie el clima.',
            'Compare capacity, output and recovery time across every unit in stock.' => 'Compara capacidad, potencia y tiempo de recarga en cada unidad disponible.',
            'Browse all power stations →' => 'Ver todas las estaciones →',
            'Backup power gear for storms, outages, and everything the grid can\'t guarantee.' => 'Equipo de energía de respaldo para tormentas, apagones y todo lo que la red no garantiza.',
            '>Page<' => '>Página<',

            // Common totals (keep Subtotal/Total as cognates OK, still map Shipping)
            '>Shipping<' => '>Envío<',
            '>Discount<' => '>Descuento<',
        ];
    }
}
