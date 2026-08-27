<?php

namespace App\Services\Storefront\Modules;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

class TwigStorefrontRenderer
{
    protected ?Environment $env = null;

    public function environment(): Environment
    {
        if ($this->env instanceof Environment) {
            return $this->env;
        }

        $loader = new FilesystemLoader(resource_path('views/storefront/modules'));
        $twig = new Environment($loader, [
            'autoescape' => 'html',
            'cache' => false,
            'strict_variables' => false,
        ]);

        $policy = new SecurityPolicy(
            ['if', 'for', 'set', 'include'],
            ['escape', 'e', 'raw', 'length', 'default', 'join', 'first', 'last', 'keys', 'upper', 'lower', 'trim', 'nl2br', 'date', 'number_format', 'slice', 'abs', 'replace'],
            [],
            [],
            ['min', 'max', 'range']
        );
        $twig->addExtension(new SandboxExtension($policy, true));

        $this->env = $twig;

        return $this->env;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderFile(string $template, array $data): string
    {
        try {
            return $this->environment()->render($template, $data);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Twig module failed', [
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Twig sandbox para copy estático (FAQ / nosotros). Solo {{ }} / {% if %}.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderString(string $source, array $data): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        try {
            $loader = new ArrayLoader(['static.twig' => $source]);
            $twig = new Environment($loader, [
                'autoescape' => 'html',
                'cache' => false,
                'strict_variables' => false,
            ]);
            $policy = new SecurityPolicy(
                ['if', 'for', 'set'],
                ['escape', 'e', 'raw', 'length', 'default', 'upper', 'lower', 'trim', 'nl2br'],
                [],
                [],
                []
            );
            $twig->addExtension(new SandboxExtension($policy, true));

            return $twig->render('static.twig', $data);
        } catch (\Throwable) {
            return $source;
        }
    }
}
