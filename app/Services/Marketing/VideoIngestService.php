<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\MarketingVideo;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoIngestService
{
    public function maxBytes(): int
    {
        return max(1, (int) config('multidrop.marketing.max_video_mb', 80)) * 1024 * 1024;
    }

    public function ffmpegBinary(): string
    {
        $bin = trim((string) config('multidrop.marketing.ffmpeg_path', 'ffmpeg'));

        return $bin !== '' ? $bin : 'ffmpeg';
    }

    public function ffmpegAvailable(): bool
    {
        $bin = $this->ffmpegBinary();
        try {
            $result = Process::timeout(8)->run([$bin, '-version']);

            return $result->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ingestUpload(
        Store $store,
        MarketingCampaign $campaign,
        UploadedFile $file,
        ?MarketingPrompt $prompt = null
    ): MarketingVideo {
        $dir = 'marketing/'.$store->id;
        Storage::disk('public')->makeDirectory($dir);
        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        if (! in_array($ext, ['mp4', 'webm', 'mov'], true)) {
            $ext = 'mp4';
        }
        $basename = Str::uuid()->toString().'.'.$ext;
        $stored = $file->storeAs($dir, $basename, 'public');
        $rel = is_string($stored) && $stored !== '' ? $stored : $dir.'/'.$basename;

        return $this->persistCleaned($store, $campaign, $rel, $this->neutralName($file->getClientOriginalName()), 'upload', $prompt, null);
    }

    public function ingestFromUrl(
        Store $store,
        MarketingCampaign $campaign,
        string $url,
        ?MarketingPrompt $prompt = null,
        ?string $jobId = null
    ): MarketingVideo {
        $dir = 'marketing/'.$store->id;
        Storage::disk('public')->makeDirectory($dir);
        $basename = Str::uuid()->toString().'.mp4';
        $rel = $dir.'/'.$basename;
        $abs = Storage::disk('public')->path($rel);
        $body = file_get_contents($url);
        if ($body === false || $body === '') {
            throw new \RuntimeException('No se pudo descargar el video.');
        }
        file_put_contents($abs, $body);

        return $this->persistCleaned($store, $campaign, $rel, $this->neutralName(null), 'creatify', $prompt, $jobId);
    }

    protected function persistCleaned(
        Store $store,
        MarketingCampaign $campaign,
        string $rel,
        string $originalName,
        string $source,
        ?MarketingPrompt $prompt,
        ?string $jobId
    ): MarketingVideo {
        $abs = Storage::disk('public')->path($rel);
        $strippedAt = null;
        if (is_file($abs) && $this->ffmpegAvailable()) {
            $cleanRel = $this->stripMetadata($rel);
            if ($cleanRel !== null) {
                $rel = $cleanRel;
                $strippedAt = now();
            }
        }

        return MarketingVideo::create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'prompt_id' => $prompt?->id,
            'source' => $source,
            'path' => $rel,
            'original_name' => mb_substr($originalName, 0, 180),
            'duration' => null,
            'page_handles' => [],
            'stripped_at' => $strippedAt,
            'creatify_job_id' => $jobId,
        ]);
    }

    /**
     * Remux a MP4 genérico: sin título, encoder, comment ni huellas de software/IA.
     */
    protected function stripMetadata(string $rel): ?string
    {
        $disk = Storage::disk('public');
        $src = $disk->path($rel);
        $destRel = pathinfo($rel, PATHINFO_DIRNAME).'/'.Str::uuid()->toString().'.mp4';
        $dest = $disk->path($destRel);
        $bin = $this->ffmpegBinary();
        $args = array_merge(
            [$bin, '-hide_banner', '-loglevel', 'error', '-y', '-i', $src],
            $this->neutralMetadataArgs(),
            [
                '-map', '0',
                '-c', 'copy',
                '-dn',
                '-sn',
                '-movflags', '+faststart',
                $dest,
            ]
        );
        try {
            $result = Process::timeout(180)->run($args);
        } catch (\Throwable) {
            return null;
        }
        if (! $result->successful() || ! is_file($dest) || filesize($dest) < 64) {
            @unlink($dest);

            return null;
        }
        $disk->delete($rel);

        return $destRel;
    }

    /**
     * @return list<string>
     */
    protected function neutralMetadataArgs(): array
    {
        $keys = [
            'title', 'comment', 'description', 'synopsis', 'artist', 'album',
            'genre', 'copyright', 'encoder', 'encoded_by', 'software', 'composer',
            'publisher', 'handler_name', 'major_brand', 'compatible_brands',
        ];
        $args = ['-map_metadata', '-1', '-fflags', '+bitexact', '-flags:v', '+bitexact', '-flags:a', '+bitexact'];
        foreach ($keys as $key) {
            $args[] = '-metadata';
            $args[] = $key.'=';
        }
        $args[] = '-metadata:s:v:0';
        $args[] = 'handler_name=VideoHandler';
        $args[] = '-metadata:s:v:0';
        $args[] = 'encoder=';
        $args[] = '-metadata:s:a:0';
        $args[] = 'handler_name=SoundHandler';
        $args[] = '-metadata:s:a:0';
        $args[] = 'encoder=';

        return $args;
    }

    public function downloadName(MarketingVideo $video): string
    {
        return $this->neutralName($video->original_name);
    }

    protected function neutralName(?string $name): string
    {
        $base = strtolower((string) $name);
        if ($base === '' || str_contains($base, 'creatify') || str_contains($base, 'arcads') || str_contains($base, 'ai-') || str_contains($base, 'generated')) {
            return 'clip.mp4';
        }
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $name) ?: 'clip.mp4';

        return str_ends_with(strtolower($clean), '.mp4') ? $clean : $clean.'.mp4';
    }

    public function delete(MarketingVideo $video): void
    {
        if ($video->path) {
            Storage::disk('public')->delete($video->path);
        }
        $video->delete();
    }

    public function copyToCampaign(
        Store $store,
        MarketingCampaign $campaign,
        MarketingVideo $source,
        ?MarketingPrompt $prompt = null
    ): ?MarketingVideo {
        if (! $source->path || ! Storage::disk('public')->exists($source->path)) {
            return null;
        }
        $dir = 'marketing/'.$store->id;
        Storage::disk('public')->makeDirectory($dir);
        $ext = strtolower((string) pathinfo($source->path, PATHINFO_EXTENSION)) ?: 'mp4';
        if (! in_array($ext, ['mp4', 'webm', 'mov'], true)) {
            $ext = 'mp4';
        }
        $rel = $dir.'/'.Str::uuid()->toString().'.'.$ext;
        Storage::disk('public')->copy($source->path, $rel);

        return MarketingVideo::create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'prompt_id' => $prompt?->id,
            'source' => $source->source,
            'path' => $rel,
            'original_name' => $source->original_name,
            'ad_headline' => $source->ad_headline,
            'ad_primary_text' => $source->ad_primary_text,
            'ad_cta' => $source->ad_cta,
            'duration' => $source->duration,
            'page_handles' => $source->pageHandleList(),
            'stripped_at' => $source->stripped_at,
            'creatify_job_id' => null,
        ]);
    }
}
