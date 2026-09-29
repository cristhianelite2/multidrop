<?php

namespace App\Services\Marketing;

/**
 * Catálogo de estilos de anuncio.
 *
 * Lee `tools/remotion-ads/styles.json` (misma fuente que consumen
 * `python/styles.py` y `src/styles.ts`) para que Laravel pueda elegir un estilo
 * por generación, mostrarlos en la UI y validar el preset recibido.
 */
class RemotionStyleCatalog
{
    /** @var list<array<string, mixed>>|null */
    protected ?array $cache = null;

    public function path(): string
    {
        $root = rtrim(
            (string) config('multidrop.marketing.remotion.root', base_path('tools/remotion-ads')),
            DIRECTORY_SEPARATOR
        );

        return $root.DIRECTORY_SEPARATOR.'styles.json';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defaults = [];
        $estilos = [];
        $path = $this->path();
        if (is_file($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) {
                $defaults = is_array($raw['defaults'] ?? null) ? $raw['defaults'] : [];
                foreach ((array) ($raw['styles'] ?? []) as $estilo) {
                    if (! is_array($estilo) || ($estilo['id'] ?? '') === '') {
                        continue;
                    }
                    $estilos[] = array_merge($defaults, $estilo);
                }
            }
        }

        if ($estilos === []) {
            $estilos = [array_merge($defaults, [
                'id' => 'social_ad',
                'label' => 'Social Ad',
            ])];
        }

        return $this->cache = $estilos;
    }

    public function exists(string $id): bool
    {
        return in_array($id, $this->ids(), true);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_map(
            fn (array $estilo) => (string) $estilo['id'],
            $this->all()
        ));
    }

    /**
     * @return array<string, string> id => etiqueta
     */
    public function labels(): array
    {
        $out = [];
        foreach ($this->all() as $estilo) {
            $out[(string) $estilo['id']] = (string) ($estilo['label'] ?? $estilo['id']);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $estilo) {
            if ((string) $estilo['id'] === $id) {
                return $estilo;
            }
        }

        return null;
    }

    public function defaultId(): string
    {
        $config = trim((string) config('multidrop.marketing.remotion.default_style', ''));

        return $this->exists($config) ? $config : (string) $this->ids()[0];
    }

    /**
     * `random` (o cualquier preset desconocido) se convierte en un id concreto
     * antes de encolar, para que todo el pipeline use siempre un estilo real.
     *
     * @return array{id: string, label: string, style: array<string, mixed>}
     */
    public function resolve(string $requested): array
    {
        $id = trim($requested);
        if ($id === '' || strtolower($id) === 'random' || ! $this->exists($id)) {
            $id = $this->ids()[array_rand($this->ids())];
        }

        $style = $this->find($id) ?? $this->all()[0];

        return [
            'id' => (string) $style['id'],
            'label' => (string) ($style['label'] ?? $style['id']),
            'style' => $style,
        ];
    }

    /** Ids aceptados por el endpoint (incluye `random`). @return list<string> */
    public function options(): array
    {
        return ['random', ...$this->ids()];
    }
}
