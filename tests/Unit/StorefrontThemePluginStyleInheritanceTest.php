<?php

namespace Tests\Unit;

use App\Services\Storefront\DesignThemeService;
use App\Services\Storefront\Modules\ModuleRegistry;
use Tests\TestCase;

class StorefrontThemePluginStyleInheritanceTest extends TestCase
{
    public function test_compose_storefront_css_always_includes_starter_modules_base_even_with_custom_modules_css(): void
    {
        $service = app(DesignThemeService::class);

        $design = [
            'global_css' => ':root { --md-primary: #C86D51; --md-accent: #E29578; --md-bg: #FAF6F0; --md-panel: #F3ECE1; --md-ink: #3A2618; --md-radius: 16px; --md-radius-sm: 12px; }',
            'modules_css' => '.md-mod-urgency { background: var(--md-primary); color: #fff; }',
            'checkout' => $service->defaults()['checkout'],
        ];

        $css = $service->composeStorefrontCss($design, ['type' => 'landing', 'css' => '']);

        // Base plugin rules from starterModulesCss() must remain present even when custom modules_css is provided
        $this->assertStringContainsString('.md-mod-roulette-fab', $css);
        $this->assertStringContainsString('.md-mod-upsell-product', $css);
        $this->assertStringContainsString('.md-mod-social.md-sp-visible', $css);
        $this->assertStringContainsString('.md-mod-cross-checkout__card', $css);
        $this->assertStringContainsString('.md-mod-cookies__bar', $css);
        $this->assertStringContainsString('.md-country-picker__panel', $css);

        // Custom modules_css rules must also be preserved on top
        $this->assertStringContainsString('.md-mod-urgency { background: var(--md-primary); color: #fff; }', $css);

        // Token bridge maps template variables to --md-mod-* and --md-checkout-*
        $this->assertStringContainsString('--md-primary: #C86D51;', $css);
        $this->assertStringContainsString('--md-checkout-primary: #C86D51;', $css);
        $this->assertStringContainsString('--md-mod-primary: var(--md-primary);', $css);
        $this->assertStringContainsString('--md-mod-bg: var(--md-panel);', $css);
        $this->assertStringContainsString('--md-mod-radius: var(--md-radius);', $css);
    }

    public function test_extract_theme_tokens_and_checkout_tokens_preserve_contrast_on_dark_templates(): void
    {
        $service = app(DesignThemeService::class);

        // Emergency-power style: --md-ink is dark (#14181A) for buttons, while --md-paper (#EEF1F0) is the light text on dark --md-slate (#1D2226)
        $design = [
            'global_css' => ':root { --md-graphite: #14181A; --md-slate: #1D2226; --md-steel: #2B3236; --md-paper: #EEF1F0; --md-amber: #F2A93B; --md-ink: #14181A; }',
            'modules_css' => '',
            'checkout' => $service->defaults()['checkout'],
        ];

        $tokens = $service->extractThemeTokens($design);
        $this->assertSame('#F2A93B', $tokens['primary']);
        $this->assertSame('#14181A', $tokens['bg']);
        $this->assertSame('#1D2226', $tokens['panel']);
        $this->assertSame('#EEF1F0', $tokens['ink']);
        $this->assertSame('#0f172a', $tokens['on_primary']);
        $this->assertTrue($tokens['is_dark']);

        $bridge = $service->buildThemeTokenBridgeCss($design);
        // Preserves raw --md-ink for the theme's own selectors while setting --md-mod-text to contrast-checked light text
        $this->assertStringContainsString('--md-ink: #14181A;', $bridge);
        $this->assertStringContainsString('--md-mod-text: #EEF1F0;', $bridge);

        $checkout = $service->resolveEffectiveCheckoutTokens($design);
        $this->assertSame('#F2A93B', $checkout['primary']);
        $this->assertSame('#1D2226', $checkout['bg']);
        $this->assertSame('#EEF1F0', $checkout['text']);
    }

    public function test_sanitize_theme_css_rewrites_misplaced_atc_modal_and_cookies_wrapper_rules(): void
    {
        $service = app(DesignThemeService::class);

        $brokenCss = <<<'CSS'
#md-atc-modal {
  background: #ffffff;
  border-radius: var(--md-radius);
  padding: 24px;
  box-shadow: 0 20px 50px rgba(58, 38, 24, 0.2);
  max-width: 420px;
  width: 90%;
}
.md-mod-cookies {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: #3A2618;
  color: #FAF6F0;
  padding: 16px 24px;
  z-index: 9998;
}
CSS;

        $sanitized = $service->sanitizeThemeCss($brokenCss);

        $this->assertStringContainsString('#md-atc-modal .md-atc-modal__card {', $sanitized);
        $this->assertStringNotContainsString("\n#md-atc-modal {", "\n".$sanitized);
        $this->assertStringContainsString('.md-mod-cookies .md-mod-cookies__bar {', $sanitized);
    }

    public function test_module_registry_injects_urgency_on_landing_and_product_unless_explicitly_disabled(): void
    {
        $registry = app(ModuleRegistry::class);

        // Template layout.json omitted urgency on landing
        $landingPage = [
            'type' => 'landing',
            'modules' => ['header', 'hero_star', 'product_grid', 'footer'],
        ];
        $layout = $registry->layoutFor($landingPage, 'desktop');
        $this->assertSame(['header', 'urgency', 'hero_star', 'product_grid', 'footer'], $layout);

        // Explicitly disabled urgency in page modules should be respected
        $disabledUrgencyPage = [
            'type' => 'landing',
            'modules' => [
                ['key' => 'header', 'desktop' => true, 'mobile' => true],
                ['key' => 'urgency', 'desktop' => false, 'mobile' => false],
                ['key' => 'hero_star', 'desktop' => true, 'mobile' => true],
                ['key' => 'footer', 'desktop' => true, 'mobile' => true],
            ],
        ];
        $layoutDisabled = $registry->layoutFor($disabledUrgencyPage, 'desktop');
        $this->assertSame(['header', 'hero_star', 'footer'], $layoutDisabled);
    }

    public function test_ghost_button_selectors_are_protected_against_white_text_override(): void
    {
        $service = app(DesignThemeService::class);

        $themeCss = <<<'CSS'
.md-btn, button.md-btn, a.md-btn {
  background: #F59E0B;
  color: #FFFFFF !important;
}
.md-btn--ghost {
  background: var(--md-panel-2) !important;
  color: var(--md-primary) !important;
}
CSS;

        $sanitized = $service->sanitizeThemeCss($themeCss);

        // Standalone .md-btn--ghost must be expanded so a.md-btn and button.md-btn don't override its color
        $this->assertStringContainsString('a.md-btn--ghost', $sanitized);
        $this->assertStringContainsString('button.md-btn--ghost', $sanitized);
        $this->assertStringContainsString('.md-btn.md-btn--ghost', $sanitized);

        $composed = $service->composeStorefrontCss([
            'global_css' => $themeCss,
            'modules_css' => '',
            'checkout' => $service->defaults()['checkout'],
        ]);

        // Platform guard must ensure ghost buttons keep primary/readable text
        $this->assertStringContainsString('a.md-btn--ghost', $composed);
        $this->assertStringContainsString('button.md-btn--ghost', $composed);
        $this->assertStringContainsString('#md-atc-continue', $composed);
    }
}
