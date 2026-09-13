<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->expectedToken();
        $token = (string) ($request->bearerToken() ?: $request->header('X-Multidrop-Token', ''));

        if ($expected === '' || $token === '' || ! hash_equals($expected, $token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token de API inválido o ausente. Usa «Authorization: Bearer <token>» o «X-Multidrop-Token: <token>».',
            ], 401);
        }

        return $next($request);
    }

    protected function expectedToken(): string
    {
        $token = (string) PlatformSetting::getValue('api.public_token', '');

        if ($token === '') {
            $token = (string) config('multidrop.api_token', '');
        }

        return $token;
    }
}