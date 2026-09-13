<?php

namespace App\Console\Commands;

use App\Models\MarketingVideo;
use App\Services\Marketing\VideoAdCopyService;
use Illuminate\Console\Command;

class BackfillVideoAdCopyCommand extends Command
{
    protected $signature = 'marketing:backfill-video-copy {--campaign=}';

    protected $description = 'Rellena ad_headline/ad_primary_text/ad_cta desde el prompt vinculado';

    public function handle(VideoAdCopyService $adCopy): int
    {
        $q = MarketingVideo::query()->whereNotNull('prompt_id')->with('prompt');
        if ($this->option('campaign')) {
            $q->where('campaign_id', (int) $this->option('campaign'));
        }

        $updated = 0;
        foreach ($q->cursor() as $video) {
            $prompt = $video->prompt;
            if (! $prompt) {
                continue;
            }
            if ($video->ad_headline && $video->ad_primary_text) {
                continue;
            }
            $fields = $adCopy->fromPrompt($prompt);
            $video->fill([
                'ad_headline' => $video->ad_headline ?: $fields['ad_headline'],
                'ad_primary_text' => $video->ad_primary_text ?: $fields['ad_primary_text'],
                'ad_cta' => $video->ad_cta ?: $fields['ad_cta'],
            ])->save();
            $updated++;
        }

        $this->info("Videos actualizados: {$updated}");

        return self::SUCCESS;
    }
}
