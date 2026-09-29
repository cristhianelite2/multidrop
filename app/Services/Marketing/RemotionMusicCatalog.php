<?php

namespace App\Services\Marketing;

class RemotionMusicCatalog
{
    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        $path = base_path('tools/remotion-ads/music_catalog.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $tracks = is_array($data) ? ($data['tracks'] ?? []) : [];

        $keyed = [];
        foreach ($tracks as $track) {
            if (is_array($track) && ! empty($track['id'])) {
                $keyed[(string) $track['id']] = $track;
            }
        }

        return $keyed;
    }

    /** @return array<string, string>|null */
    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }
}
