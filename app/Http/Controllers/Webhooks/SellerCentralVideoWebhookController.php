<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SellerCentralVideoJob;
use App\Services\SellerCentral\SellerCentralVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class SellerCentralVideoWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, SellerCentralVideoService $videos): JsonResponse
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            return response()->json(['ok' => false, 'message' => 'Token inválido'], 404);
        }

        $job = SellerCentralVideoJob::query()
            ->where('callback_token', $token)
            ->first();

        if (! $job) {
            return response()->json(['ok' => false, 'message' => 'Job no encontrado'], 404);
        }

        $fields = [
            'task_id' => $request->input('task_id'),
            'project_id' => $request->input('project_id'),
            'title' => $request->input('title'),
            'status' => $request->input('status', 'completed'),
            'notebook_id' => $request->input('notebook_id'),
            'video_url' => $request->input('video_url'),
            'video_files' => $request->input('video_files'),
            'video_log' => $request->input('video_log'),
            'error_message' => $request->input('error_message'),
            'multidrop_product_id' => $request->input('multidrop_product_id'),
            'multidrop_product_name' => $request->input('multidrop_product_name'),
            'multidrop_product_url' => $request->input('multidrop_product_url'),
        ];

        $main = $request->file('video');
        $extras = [];
        $extra = $request->file('videos');
        if ($extra) {
            foreach (is_array($extra) ? $extra : [$extra] as $file) {
                if ($file instanceof UploadedFile) {
                    $extras[] = $file;
                }
            }
        }

        try {
            $job = $videos->handleCallback(
                $job,
                $fields,
                $main instanceof UploadedFile ? $main : null,
                $extras
            );
        } catch (\Throwable $e) {
            Log::error('sellercentral video webhook failed', [
                'job' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'job_id' => $job->id,
            'status' => $job->status,
            'marketing_video_id' => $job->marketing_video_id,
        ]);
    }
}
