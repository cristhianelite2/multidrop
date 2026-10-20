<?php

namespace Tests\Unit;

use App\Models\StoreDesign;
use App\Services\Storefront\DesignZipExporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use ZipArchive;

class StoreDesignZipExportTest extends TestCase
{
    public function test_exports_store_design_zip_and_registers_download_route(): void
    {
        $this->assertTrue(Route::has('admin.store.designs.zip'), 'Route admin.store.designs.zip should exist');

        $storeDesign = new StoreDesign([
            'name' => 'emergency-power-theme-template',
            'design' => [
                'global_css' => ':root { --md-primary: #ff6600; }',
                'pages' => [
                    [
                        'id' => 'p1',
                        'type' => 'page',
                        'handle' => 'faq',
                        'title' => 'FAQ',
                        'html' => '<section>FAQ content</section>',
                        'status' => 'live',
                    ],
                ],
                'assets' => [],
            ],
        ]);

        $exporter = app(DesignZipExporter::class);
        $zipPath = $exporter->exportStoreDesign($storeDesign);

        $this->assertNotNull($zipPath);
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->locateName('emergency-power-theme-template/theme.css'));
        $this->assertNotFalse($zip->locateName('emergency-power-theme-template/pages/faq.twig'));
        $zip->close();

        File::delete($zipPath);
    }
}