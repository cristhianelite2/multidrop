<?php

namespace App\Services\Marketing\VideoPlan;

/**
 * Catálogo permitido para planes Miia → HyperFrames.
 * Valores alineados con build_composition / VideoPlanSanitizer.
 */
class VideoPlanCatalog
{
    /**
     * @return array{width: int, height: int, fps: int, duration: float}
     */
    public function video(): array
    {
        return [
            'width' => 1080,
            'height' => 1920,
            'fps' => 30,
            'duration' => 29.0,
        ];
    }

    public function defaultTemplate(): string
    {
        return 'catalog_pop';
    }

    public function isTemplate(string $template): bool
    {
        return in_array($template, $this->templates(), true);
    }

    /**
     * @return list<string>
     */
    public function templates(): array
    {
        return ['catalog_pop', 'product_ad', 'ugc_lite'];
    }

    public function isComponent(string $type): bool
    {
        return in_array($type, $this->components(), true);
    }

    /**
     * @return list<string>
     */
    public function components(): array
    {
        return [
            'headline',
            'subtitle',
            'kinetic_subtitle',
            'feature',
            'feature_list',
            'badge',
            'price',
            'cta',
            'product_card',
        ];
    }

    public function isAnimation(string $name): bool
    {
        return in_array($name, $this->animations(), true);
    }

    /**
     * @return list<string>
     */
    public function animations(): array
    {
        return [
            'none',
            'fade',
            'slide_up',
            'slide_down',
            'slide_left',
            'slide_right',
            'pop',
            'scale_in',
            'scale_out',
            'typewriter',
            'blur_in',
        ];
    }

    public function isTransition(string $name): bool
    {
        return in_array($name, $this->transitions(), true);
    }

    /**
     * @return list<string>
     */
    public function transitions(): array
    {
        return ['none', 'fade', 'wipe', 'slide', 'cut'];
    }
}
