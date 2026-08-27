<?php

namespace App\Console\Commands;

use App\Models\StoreDesign;
use App\Models\Theme;
use App\Services\Storefront\DesignThemeService;
use App\Services\Storefront\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class MigrateDesignModulesCommand extends Command
{
    protected $signature = 'design:migrate-modules {--dry-run : No guarda}';

    protected $description = 'Pasa plantillas y copias de tienda al layout de módulos Twig (CSS override, sin HTML comercial).';

    public function handle(DesignThemeService $themes, ModuleRegistry $registry): int
    {
        $dry = (bool) $this->option('dry-run');
        $nThemes = 0;
        $nStores = 0;

        Theme::query()->orderBy('id')->each(function (Theme $theme) use ($themes, $registry, $dry, &$nThemes) {
            $design = $themes->normalizeDesign(is_array($theme->design) ? $theme->design : [], $theme->name);
            $design = $this->migrateDesign($design, $registry);
            if (! $dry) {
                $theme->design = $design;
                $theme->save();
            }
            $nThemes++;
            $this->line(($dry ? '[dry] ' : '').'Theme #'.$theme->id.' '.$theme->slug);
        });

        StoreDesign::query()->orderBy('id')->each(function (StoreDesign $row) use ($themes, $registry, $dry, &$nStores) {
            $design = $themes->normalizeDesign(is_array($row->design) ? $row->design : [], $row->name ?: 'Tienda');
            $design = $this->migrateDesign($design, $registry);
            if (! $dry) {
                $row->design = $design;
                $row->save();
            }
            $nStores++;
            $this->line(($dry ? '[dry] ' : '').'StoreDesign #'.$row->id.' store='.$row->store_id);
        });

        $this->info("Listo: {$nThemes} plantillas, {$nStores} copias de tienda.".($dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    protected function migrateDesign(array $design, ModuleRegistry $registry): array
    {
        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        foreach ($pages as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            $type = (string) ($page['type'] ?? 'page');
            $page['modules'] = $registry->defaultLayout($type);
            if ($registry->isCommercial($type)) {
                $page['html'] = '';
            }
            $js = (string) ($page['js'] ?? '');
            if (preg_match('/\b(renderGrids|bindAddToCart|MD\.Cart\s*=)/i', $js)) {
                $page['js'] = '';
            }
            $pages[$i] = $page;
        }
        $design['pages'] = $pages;

        $gjs = (string) ($design['global_js'] ?? '');
        if (preg_match('/\b(renderGrids|bindAddToCart|localStorage|MD\.Cart\s*=|Multidrop\.Cart\s*=)/i', $gjs)) {
            $design['global_js'] = "/* theme.js recortado: carrito y grids los renderiza Multidrop */\n";
        }

        return $design;
    }
}
