<?php

namespace Tests\Unit;

use App\Domain\Suppliers\AliExpress\AliExpressProductFetcher;
use ReflectionClass;
use Tests\TestCase;

class AliExpressCaptureTitleTest extends TestCase
{
    public function test_visible_product_heading_wins_over_campaign_title_in_embedded_data(): void
    {
        $fetcher = (new ReflectionClass(AliExpressProductFetcher::class))
            ->newInstanceWithoutConstructor();
        $visibleTitle = 'Bolígrafos de gel borrables de capibara lindos de 6 piezas | Tinta azul retráctil de 0,5 mm para la escuela y la oficina | Escritura suave y de secado rápido (6 colores)';
        $html = <<<'HTML'
            <html><head><script>
            window.runParams = {"data":{"titleModule":{"subject":"Bundle Deals 2.0"},"descriptionModule":{"description":"Descripción suficientemente larga del producto para evitar una consulta remota durante la prueba."}}};
            </script></head><body><h1 data-pl="product-title">Bolígrafos de gel borrables de capibara lindos de 6 piezas | Tinta azul retráctil de 0,5 mm para la escuela y la oficina | Escritura suave y de secado rápido (6 colores)</h1></body></html>
            HTML;

        $result = $fetcher->parseFromCapture($html, 'https://es.aliexpress.com/item/1005001234567890.html', [
            'h1' => $visibleTitle,
        ], ['title']);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? 'No se pudo parsear la captura.');
        $this->assertSame($visibleTitle, $result['product']['title']);
    }
}
