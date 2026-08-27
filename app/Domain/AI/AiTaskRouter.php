<?php

namespace App\Domain\AI;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiTaskRouter
{
    public const CACHE_KEY = 'ai.miia.engines.v4';

    public function __construct(protected AiManager $ai) {}

    public function hasMiia(): bool
    {
        return (bool) config('ai.providers.miia.api_key');
    }

    /**
     * @return array<string, array{label: string, hint?: string, kind: string, default_engine: string}>
     */
    public function tasks(): array
    {
        $tasks = config('ai.tasks', []);

        return is_array($tasks) ? $tasks : [];
    }

    /**
     * @return array{label: string, hint?: string, kind: string, default_engine: string}|null
     */
    public function taskMeta(string $task): ?array
    {
        $meta = $this->tasks()[$task] ?? null;

        return is_array($meta) ? $meta : null;
    }

    /**
     * @return array<string, string>
     */
    public function savedEngines(): array
    {
        $fromConfig = config('ai.task_engines');
        if (is_array($fromConfig) && $fromConfig !== []) {
            $out = [];
            foreach ($fromConfig as $task => $engine) {
                $out[(string) $task] = trim((string) $engine);
            }

            return array_filter($out, fn ($v) => $v !== '');
        }

        $raw = PlatformSetting::getValue('ai.task_engines');
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $task => $engine) {
            $val = trim((string) $engine);
            if ($val !== '') {
                $out[(string) $task] = $val;
            }
        }

        return $out;
    }

    public function engineFor(string $task): string
    {
        $saved = $this->savedEngines();
        $chosen = trim((string) ($saved[$task] ?? ''));

        return $this->sanitizeEngineForTask($task, $chosen);
    }

    public function sanitizeEngineForTask(string $task, string $engine): string
    {
        $meta = $this->taskMeta($task);
        $kind = (string) ($meta['kind'] ?? 'chat');
        $default = trim((string) ($meta['default_engine'] ?? ''));
        if ($default === '') {
            $default = $kind === 'image' ? 'gpt-image-1.5' : 'free';
        }

        $engine = trim($engine);
        if ($engine === '') {
            return $default;
        }

        $isImage = self::looksLikeImageEngine($engine);
        if ($kind === 'image') {
            return $isImage ? $engine : $default;
        }

        return $isImage ? $default : $engine;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function chatOptionsFor(string $task, array $extra = []): array
    {
        return array_merge($this->engineToChatOptions($this->engineFor($task)), $extra);
    }

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{success: bool, content?: string, raw?: mixed, error?: string, provider: string, engine?: string, task?: string}
     */
    public function chat(string $task, array $messages, array $options = []): array
    {
        if (! $this->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en Admin → General.',
                'provider' => 'miia',
            ];
        }

        $merged = $this->chatOptionsFor($task, $options);
        $result = $this->ai->driver('miia')->chat($messages, $merged);
        $result['engine'] = $this->engineFor($task);
        $result['task'] = $task;

        return $result;
    }

    /**
     * @return array{
     *     success: bool,
     *     api_key?: string,
     *     base_url?: string,
     *     model?: string,
     *     services?: list<string>,
     *     timeout?: int,
     *     engine?: string,
     *     error?: string,
     *     provider?: string
     * }
     */
    public function imageContext(string $task = 'combo_image'): array
    {
        $apiKey = config('ai.providers.miia.api_key');
        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en Admin → General para generar imágenes.',
                'provider' => 'miia',
            ];
        }

        $engine = $this->engineFor($task);
        $chatOpts = $this->engineToChatOptions($engine);
        $model = (string) ($chatOpts['model'] ?? $engine);
        if ($model === '' || $model === 'auto') {
            $model = $engine !== '' ? $engine : 'gpt-image-1.5';
        }

        $base = rtrim((string) config('ai.providers.miia.base_url', 'https://ia.ceballosleon.com'), '/');

        return [
            'success' => true,
            'api_key' => $apiKey,
            'base_url' => $base.'/v1',
            'model' => $model,
            'services' => array_values($chatOpts['services'] ?? []),
            'timeout' => (int) config('ai.providers.miia.image_timeout', 180),
            'engine' => $engine,
            'provider' => 'miia',
        ];
    }

    /**
     * @return list<array{id: string, label: string, kind: string}>
     */
    public function listEngines(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $fetched = $this->fetchEnginesFromApi();

            return $fetched !== [] ? $fetched : $this->fallbackEngines();
        });
    }

    /**
     * @return list<array{id: string, label: string, kind: string}>
     */
    public function fallbackEngines(): array
    {
        $rows = config('ai.fallback_engines', []);
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = trim((string) $row['id']);
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'label' => (string) ($row['label'] ?? $id),
                'kind' => (string) ($row['kind'] ?? (self::looksLikeImageEngine($id) ? 'image' : 'chat')),
            ];
        }

        return $out;
    }

    public static function looksLikeImageEngine(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        if (preg_match('/image|dall-?e|flux|imagen|seedream|ideogram|recraft|midjourney|nano-banana/i', $id)) {
            return true;
        }
        foreach (config('ai.preferred_image_engines', []) as $row) {
            if (is_array($row) && strcasecmp((string) ($row['id'] ?? ''), $id) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mayor = mejor para combos promocionales 9:16 con plantillas.
     */
    public static function imageEngineRank(string $id): int
    {
        $id = strtolower(trim($id));
        $ranks = [
            'gpt-image-1.5' => 100,
            'gpt-image-1' => 90,
            'gpt-image' => 85,
            'dall-e-3' => 70,
            'models/gemini-3.1-flash-lite-image' => 45,
            'google/gemini-2.0-flash-exp:free' => 35,
            'dall-e-2' => 25,
        ];
        if (isset($ranks[$id])) {
            return $ranks[$id];
        }
        if (str_contains($id, 'gpt-image')) {
            return 80;
        }
        if (str_contains($id, 'dall-e-3') || str_contains($id, 'dall-e3')) {
            return 70;
        }
        if (self::looksLikeImageEngine($id)) {
            return 40;
        }

        return 0;
    }

    /**
     * @param  list<string>  $availableIds
     */
    public function defaultEngineFor(string $task, array $availableIds = []): string
    {
        $meta = $this->taskMeta($task);
        $kind = (string) ($meta['kind'] ?? 'chat');
        $preferred = trim((string) ($meta['default_engine'] ?? ''));

        if ($availableIds === []) {
            return $preferred !== '' ? $preferred : ($kind === 'image' ? 'gpt-image-1.5' : 'free');
        }

        $map = [];
        foreach ($availableIds as $id) {
            $map[strtolower((string) $id)] = (string) $id;
        }

        if ($kind === 'image') {
            foreach ([
                'gpt-image-1.5',
                'gpt-image-1',
                'gpt-image',
                'dall-e-3',
                'models/gemini-3.1-flash-lite-image',
                'google/gemini-2.0-flash-exp:free',
                'dall-e-2',
            ] as $want) {
                if (isset($map[$want])) {
                    return $map[$want];
                }
            }
            $best = null;
            $bestRank = -1;
            foreach ($availableIds as $id) {
                $rank = self::imageEngineRank((string) $id);
                if ($rank > $bestRank) {
                    $bestRank = $rank;
                    $best = (string) $id;
                }
            }
            if ($best !== null && $bestRank > 0) {
                return $best;
            }
        } else {
            foreach (['free', 'auto', 'chatgpt'] as $want) {
                if (isset($map[$want])) {
                    return $map[$want];
                }
            }
            foreach ($availableIds as $id) {
                if (! self::looksLikeImageEngine((string) $id)) {
                    return (string) $id;
                }
            }

            return $preferred !== '' ? $preferred : 'free';
        }

        if ($preferred !== '' && isset($map[strtolower($preferred)])) {
            return $map[strtolower($preferred)];
        }

        return $kind === 'image' ? 'gpt-image-1.5' : 'free';
    }

    /**
     * Mapea el motor de General a body de /v1/chat/completions.
     * Docs MIIA: model=auto (gratis) | groq/cerebras/... (priorizar servicio) | ID de modelo.
     * `free` y `chatgpt` no son valores válidos de `services`.
     *
     * @return array{model: string, services?: list<string>}
     */
    public function engineToChatOptions(string $engine): array
    {
        $engine = trim($engine);
        $lower = strtolower($engine);

        if ($engine === '' || in_array($lower, ['auto', 'free'], true)) {
            return ['model' => 'auto'];
        }

        $aliases = [
            'chatgpt' => 'openai',
            'gpt' => 'openai',
            'google' => 'gemini',
        ];
        if (isset($aliases[$lower])) {
            return ['model' => $aliases[$lower]];
        }

        $services = config('ai.miia_chat_services', []);
        if (is_array($services) && in_array($lower, $services, true)) {
            return ['model' => $lower];
        }

        return ['model' => $engine];
    }

    /**
     * @param  list<array{id: string, label: string, kind: string, rank?: int}>  $engines
     * @return list<array{id: string, label: string, kind: string, rank?: int}>
     */
    public function enginesForKind(string $kind, ?array $engines = null): array
    {
        $kind = $kind === 'image' ? 'image' : 'chat';
        $engines = $engines ?? $this->listEngines();
        $out = [];
        $seen = [];
        foreach ($engines as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $rowKind = (($row['kind'] ?? '') === 'image' || self::looksLikeImageEngine($id)) ? 'image' : 'chat';
            if ($rowKind !== $kind || isset($seen[strtolower($id)])) {
                continue;
            }
            $seen[strtolower($id)] = true;
            $out[] = [
                'id' => $id,
                'label' => $id,
                'kind' => $rowKind,
                'rank' => (int) ($row['rank'] ?? ($kind === 'image' ? self::imageEngineRank($id) : 0)),
            ];
        }

        if ($kind === 'image') {
            return $this->mergePreferredImageEngines($out);
        }

        return $this->mergePreferredChatEngines($out);
    }

    /**
     * @param  list<array{id: string, label: string, kind: string, rank?: int}>  $engines
     * @return list<array{id: string, label: string, kind: string, rank?: int}>
     */
    public function mergePreferredChatEngines(array $engines): array
    {
        $byId = [];
        foreach ($engines as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            if (($row['kind'] ?? 'chat') === 'image' || self::looksLikeImageEngine($id)) {
                continue;
            }
            $byId[strtolower($id)] = [
                'id' => $id,
                'label' => $id,
                'kind' => 'chat',
            ];
        }

        $head = [];
        foreach (config('ai.preferred_chat_engines', []) as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $key = strtolower($id);
            $item = $byId[$key] ?? ['id' => $id, 'label' => $id, 'kind' => 'chat'];
            $head[] = $item;
            unset($byId[$key]);
        }

        return array_values(array_merge($head, array_values($byId)));
    }

    /**
     * @param  list<array{id: string, label: string, kind: string, rank?: int}>  $engines
     * @return list<array{id: string, label: string, kind: string, rank?: int}>
     */
    public function mergePreferredImageEngines(array $engines): array
    {
        $byId = [];
        foreach ($engines as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            if (($row['kind'] ?? '') !== 'image' && ! self::looksLikeImageEngine($id)) {
                continue;
            }
            $byId[strtolower($id)] = [
                'id' => $id,
                'label' => $id,
                'kind' => 'image',
                'rank' => self::imageEngineRank($id),
            ];
        }

        $head = [];
        foreach (config('ai.preferred_image_engines', []) as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $key = strtolower($id);
            $item = $byId[$key] ?? [
                'id' => $id,
                'label' => $id,
                'kind' => 'image',
                'rank' => self::imageEngineRank($id),
            ];
            $item['label'] = $id;
            $item['kind'] = 'image';
            $head[] = $item;
            unset($byId[$key]);
        }

        return array_values(array_merge($head, array_values($byId)));
    }

    /**
     * @return list<array{id: string, label: string, kind: string}>
     */
    public function fetchEnginesFromApi(): array
    {
        $apiKey = config('ai.providers.miia.api_key');
        $base = rtrim((string) config('ai.providers.miia.base_url', 'https://ia.ceballosleon.com'), '/');
        if (empty($apiKey) || $base === '') {
            return [];
        }

        try {
            $res = Http::withToken($apiKey)->timeout(12)->acceptJson()->get($base.'/v1/models');
            if (! $res->successful()) {
                Log::warning('MIIA /v1/models failed', ['status' => $res->status()]);

                return [];
            }

            $data = $res->json('data');
            if (! is_array($data)) {
                return [];
            }

            $out = [];
            $seen = [];
            foreach ($data as $row) {
                $id = is_array($row) ? trim((string) ($row['id'] ?? '')) : trim((string) $row);
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $type = is_array($row) ? strtolower((string) ($row['type'] ?? '')) : '';
                $kind = ($type === 'image' || self::looksLikeImageEngine($id)) ? 'image' : 'chat';
                $out[] = [
                    'id' => $id,
                    'label' => $id,
                    'kind' => $kind,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('MIIA /v1/models exception', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
