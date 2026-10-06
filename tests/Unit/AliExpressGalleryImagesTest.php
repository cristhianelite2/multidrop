<?php

namespace Tests\Unit;

use App\Domain\Suppliers\AliExpress\AliExpressProductFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Reglas pedidas para la galería de AliExpress: solo fotos del producto o de sus
 * reseñas, y ninguna por debajo de 300x300. El markup de la PDP cambia seguido
 * (image-view-v2/slider--*), así que esto fija el comportamiento esperado.
 */
class AliExpressGalleryImagesTest extends TestCase
{
    private const CDN = 'https://ae-pic-a1.aliexpress-media.com/kf/';

    private function fetcher(): AliExpressProductFetcher
    {
        $reflection = new ReflectionClass(AliExpressProductFetcher::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return mixed
     */
    private function call(string $method, array $args)
    {
        $reflection = new ReflectionClass(AliExpressProductFetcher::class);
        $target = $reflection->getMethod($method);
        $target->setAccessible(true);

        return $target->invoke($this->fetcher(), ...$args);
    }

    /** @return list<string> */
    private function imagesFromDom(string $html): array
    {
        $images = $this->call('extractGalleryImagesFromDom', [$html]);
        $this->assertIsArray($images);

        return array_values($images);
    }

    private function html(): string
    {
        $thumb = fn (string $id) => self::CDN.$id.'.jpg?has_lang=1&amp;ver=2_220x220q75.jpg_.avif';

        return <<<HTML
        <div class="pdp-mini-wrap">
        <div class="pdp-mini-info-left">
        <div class="main-image--wrap--nFuR5UU"><div class="image-view-v2--wrap--N4InOxs">
        <div class="slider--wrap--dfLgmYD"><div class="slider--slider--VKj5hty">
        <div class="slider--item--RpyeewA"><div class="slider--img--kD4mIg7"><img src="{$thumb('Se5b226bddf244f1ca74f696d4cfa5b29K')}" alt=""></div>
        <img class="slider--videoIcon--WNGL6jY" src="{$this->cdn('S4d3b3052b6c243f08154f21134ac2b8fv/48x48.png')}"></div>
        <div class="slider--item--RpyeewA"><div class="slider--img--kD4mIg7 slider--active--KtHpSDo"><img src="{$thumb('S5ab93d660dde4746ba189d8c267bea75c')}" alt=""></div></div>
        </div></div>
        <div class="magnifier--wrap--qjbuwmt"><img class="magnifier--image--RM17RL2" src="{$this->cdn('S5ab93d660dde4746ba189d8c267bea75c.jpg_960x960q75.jpg_.avif')}"></div>
        </div></div></div>
        <div class="pdp-mini-info-center">
        <div class="price-default--wrap--uwQneeq"><div class="price-default--bannerTop--vc9A2oe">
        <img class="price-default--bannerSlogan--aLiCN5o" src="{$this->cdn('S3e6dd60d80b64ab99b4c0ead20a9cee78/522x94.png')}"></div></div>
        <div class="sku--wrap--xgoW06M"><div class="sku-item--wrap--t9Qszzx"><div class="sku-item--image--jMUnnGA">
        <img src="{$thumb('S5ab93d660dde4746ba189d8c267bea75c')}" alt="naranja"></div></div></div>
        </div>
        <div class="review--wrap--U5X0TgT" id="nav-review"><div class="ae-evaluation-list">
        <div class="ae-header-box"><div class="ae-stars-box"><img src="{$this->cdn('S3a90fde0c9cc4b8c8958a6ee7553e26bf/42x42.png')}" class="ae-stars" style="width: 24px; height: 24px;"></div></div>
        <div class="ae-filter-select-box"><img class="ae-filter-select-icon" src="{$this->cdn('S74708a54cbf84158aeaf246598b73394d/16x16.png')}"></div>
        <div class="ae-evaluateList-box"><div class="ae-evaluateList-card">
        <div class="ae-evaluateList-card-img-box"><img class="ae-evaluateList-card-img" src="http://ae-pic-a1.aliexpress-media.com/kf/A08506fa83c674409b193ca729135eff62.jpg_220x220.jpg"></div>
        <div class="ae-great-box"><img class="ae-great-img" src="{$this->cdn('S71318f97941f43bcb211b74d3353c4a5Q/24x24.png')}"></div>
        </div></div></div></div>
        <div class="description--wrap--LscZ0He" id="nav-description">
        <div id="product-description" class="description--product-description--Mjtql28"><template shadowrootmode="open">
        <div class="detail-desc-decorate-richtext"><img src="{$this->cdn('Se07d13acdbc947e580727de971daaaa9V.jpg')}"></div>
        </template></div></div>
        <div class="pdp-mini-info-right"><div class="action--container--Bv1OwjX">
        <div class="choice-mind--box--fJKH05M"><img src="{$this->cdn('S16183c3f12904fbbaf3f8aef523f0b73T.png')}"></div>
        <div class="shipping--item--F04J6q9"><img class="shipping--icon--vAVr7jZ" src="{$this->cdn('S9e723ca0d10848499e4e3fb33be2224do.png')}"></div>
        </div></div>
        </div>
        <div class="recommend--list--AbC123"><img src="{$this->cdn('SRELATED0000000000000000000000001.jpg')}"></div>
        <div class="footer--wrap--QqQ"><img class="footer--logo--WwW" src="{$this->cdn('SFOOTERLOGO00000000000000000.png')}"></div>
        </div>
        HTML;
    }

    private function cdn(string $path): string
    {
        return self::CDN.$path;
    }

    public function test_sube_las_miniaturas_del_carrusel_al_original(): void
    {
        $images = $this->imagesFromDom($this->html());

        $this->assertContains(self::CDN.'Se5b226bddf244f1ca74f696d4cfa5b29K.jpg', $images);
        $this->assertContains(self::CDN.'S5ab93d660dde4746ba189d8c267bea75c.jpg', $images);
        // La descripción aporta una foto que no está en el carrusel.
        $this->assertContains(self::CDN.'Se07d13acdbc947e580727de971daaaa9V.jpg', $images);
    }

    public function test_solo_acepta_fotos_del_producto_y_de_las_resenas(): void
    {
        $images = $this->imagesFromDom($this->html());
        $flattened = implode("\n", $images);

        foreach ([
            'S4d3b3052b6c243f08154f21134ac2b8fv' => 'ícono de video del carrusel',
            'S3e6dd60d80b64ab99b4c0ead20a9cee78' => 'banner del precio (522x94)',
            'S3a90fde0c9cc4b8c8958a6ee7553e26bf' => 'estrellas de valoración',
            'S74708a54cbf84158aeaf246598b73394d' => 'icono del filtro',
            'S71318f97941f43bcb211b74d3353c4a5Q' => 'icono de "útil"',
            'S16183c3f12904fbbaf3f8aef523f0b73T' => 'icono de compromiso AE',
            'S9e723ca0d10848499e4e3fb33be2224do' => 'icono de envío',
            'SRELATED0000000000000000000000001' => 'producto recomendado',
            'SFOOTERLOGO00000000000000000' => 'logo del pie',
        ] as $id => $descripcion) {
            $this->assertStringNotContainsString($id, $flattened, 'No debería entrar: '.$descripcion);
        }

        // Las fotos de los compradores sí valen.
        $this->assertContains(
            'http://ae-pic-a1.aliexpress-media.com/kf/A08506fa83c674409b193ca729135eff62.jpg',
            $images
        );
    }

    public function test_resolve_images_deduplica_y_conserva_el_orden(): void
    {
        $images = $this->call('resolveImages', [$this->html(), ['images' => []], null]);

        $this->assertIsArray($images);
        $this->assertSame(array_values(array_unique($images)), array_values($images));
        $this->assertSame(self::CDN.'Se5b226bddf244f1ca74f696d4cfa5b29K.jpg', $images[0]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function galleryUrlProvider(): array
    {
        return [
            'original sin sufijo' => [self::CDN.'Se5b226bddf244f1ca74f696d4cfa5b29K.jpg', true],
            'original grande' => [self::CDN.'SBIG000000000000000000000000/1600x1600.jpg', true],
            'foto de reseña' => ['http://ae-pic-a1.aliexpress-media.com/kf/A08506fa83c674409b193ca729135eff62.jpg', true],
            'ícono 48x48' => [self::CDN.'S4d3b3052b6c243f08154f21134ac2b8fv/48x48.png', false],
            'estrella 42x42' => [self::CDN.'S3a90fde0c9cc4b8c8958a6ee7553e26bf/42x42.png', false],
            'banner 522x94 (lado menor)' => [self::CDN.'S3e6dd60d80b64ab99b4c0ead20a9cee78/522x94.png', false],
            'miniatura 220x220' => [self::CDN.'Se5b226bddf244f1ca74f696d4cfa5b29K.jpg_220x220q75.jpg', false],
            'cdn ajeno' => ['https://example.com/img/foto.png', false],
        ];
    }

    #[DataProvider('galleryUrlProvider')]
    public function test_filtra_urls_por_tamano_y_origen(string $url, bool $expected): void
    {
        $this->assertSame($expected, $this->call('isGalleryImageUrl', [$url]));
    }
}
