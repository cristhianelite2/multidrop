<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerCentralVideoJob extends Model
{
    public const STATUSES = [
        'pending',
        'draft',
        'scheduled',
        'queued',
        'uploading',
        'generating',
        'completed',
        'error',
    ];

    protected $table = 'sellercentral_video_jobs';

    protected $fillable = [
        'store_id',
        'campaign_id',
        'product_id',
        'sellercentral_task_id',
        'callback_token',
        'mode',
        'status',
        'title',
        'notebook_id',
        'error_message',
        'video_log',
        'payload_snapshot',
        'remote_video_url',
        'marketing_video_id',
        'queued_at',
        'completed_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'video_log' => 'array',
            'payload_snapshot' => 'array',
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function marketingVideo(): BelongsTo
    {
        return $this->belongsTo(MarketingVideo::class, 'marketing_video_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'error'], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<array{step: string, ok: ?bool, message: string, at: ?string}>
     */
    public function steps(): array
    {
        $log = is_array($this->video_log) ? $this->video_log : [];
        $out = [];
        foreach ($log as $row) {
            if (is_string($row)) {
                $out[] = ['step' => 'log', 'ok' => null, 'message' => $row, 'at' => null];
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'step' => (string) ($row['step'] ?? 'step'),
                'ok' => array_key_exists('ok', $row) ? (bool) $row['ok'] : null,
                'message' => (string) ($row['message'] ?? $row['msg'] ?? ''),
                'at' => isset($row['at']) ? (string) $row['at'] : null,
            ];
        }

        return $out;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pendiente',
            'draft' => 'Borrador en Seller Central',
            'scheduled' => 'Programado',
            'queued' => 'En cola',
            'uploading' => 'Subiendo a NotebookLM',
            'generating' => 'Generando en NotebookLM',
            'completed' => 'Completado',
            'error' => 'Error',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toPollPayload(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'campaign_id' => $this->campaign_id,
            'task_id' => $this->sellercentral_task_id,
            'mode' => $this->mode,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'title' => $this->title,
            'notebook_id' => $this->notebook_id,
            'error_message' => $this->error_message,
            'steps' => $this->steps(),
            'remote_video_url' => $this->remote_video_url,
            'marketing_video_id' => $this->marketing_video_id,
            'is_terminal' => $this->isTerminal(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
