<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ApiController extends Controller
{
    protected function respond(mixed $data = null, int $status = 200, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => $status >= 200 && $status < 400,
            'message' => $message,
            'data' => $data,
        ], $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{items: array<int, mixed>, pagination: array<string, mixed>}
     */
    protected function paginate(LengthAwarePaginator $paginator): array
    {
        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    protected function perPage(Request $request): int
    {
        $n = (int) $request->input('per_page', 20);

        return in_array($n, [10, 20, 50, 100], true) ? $n : 20;
    }
}