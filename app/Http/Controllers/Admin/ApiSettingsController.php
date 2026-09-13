<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Request as HttpRequest;

class ApiSettingsController extends Controller
{
    public function edit()
    {
        $dbToken = trim((string) PlatformSetting::getValue('api.public_token', ''));
        $envToken = trim((string) config('multidrop.api_token', ''));
        $effective = $dbToken !== '' ? $dbToken : $envToken;

        return view('admin.settings.api', [
            'has_db_token' => $dbToken !== '',
            'has_env_token' => $envToken !== '',
            'token_source' => $dbToken !== '' ? 'panel' : ($envToken !== '' ? 'env' : 'none'),
            'masked_token' => $this->mask($effective),
            'base_url' => url('/api/v1'),
            'test_url' => route('admin.settings.api.test'),
            'new_token' => session('new_api_token'),
            'endpoints' => $this->endpoints(),
        ]);
    }

    protected function endpoints(): array
    {
        $get = 'bg-sky-100 text-sky-800';
        $post = 'bg-teal/10 text-teal';
        $put = 'bg-amber-100 text-amber-800';
        $del = 'bg-coral/10 text-coral';

        return [
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/stores', 'desc' => 'Listar tiendas (filtros q, status, store_type, per_page)'],
            ['method' => 'POST', 'color' => $post, 'path' => '/api/v1/stores', 'desc' => 'Crear tienda'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/stores/{store}', 'desc' => 'Ver tienda con settings, theme y contacto'],
            ['method' => 'PUT/PATCH', 'color' => $put, 'path' => '/api/v1/stores/{store}', 'desc' => 'Actualizar tienda'],
            ['method' => 'DELETE', 'color' => $del, 'path' => '/api/v1/stores/{store}', 'desc' => 'Eliminar tienda (409 si tiene pedidos)'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/stores/{store}/products', 'desc' => 'Buscar productos por tienda (?q=, status, is_featured, sort)'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/products', 'desc' => 'Listar productos (filtros q, status, source, store_id)'],
            ['method' => 'POST', 'color' => $post, 'path' => '/api/v1/products', 'desc' => 'Crear producto (con variantes opcionales)'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/products/{product}', 'desc' => 'Ver producto'],
            ['method' => 'PUT/PATCH', 'color' => $put, 'path' => '/api/v1/products/{product}', 'desc' => 'Actualizar producto'],
            ['method' => 'DELETE', 'color' => $del, 'path' => '/api/v1/products/{product}', 'desc' => 'Eliminar producto'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/campaigns', 'desc' => 'Listar campañas de marketing'],
            ['method' => 'POST', 'color' => $post, 'path' => '/api/v1/campaigns', 'desc' => 'Crear campaña'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/campaigns/{campaign}', 'desc' => 'Ver campaña'],
            ['method' => 'PUT/PATCH', 'color' => $put, 'path' => '/api/v1/campaigns/{campaign}', 'desc' => 'Actualizar campaña'],
            ['method' => 'DELETE', 'color' => $del, 'path' => '/api/v1/campaigns/{campaign}', 'desc' => 'Eliminar campaña'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/campaigns/{campaign}/products', 'desc' => 'Productos de la campaña'],
            ['method' => 'POST', 'color' => $post, 'path' => '/api/v1/campaigns/{campaign}/products/{product}', 'desc' => 'Agregar producto a la campaña'],
            ['method' => 'DELETE', 'color' => $del, 'path' => '/api/v1/campaigns/{campaign}/products/{product}', 'desc' => 'Quitar producto de la campaña'],
            ['method' => 'GET', 'color' => $get, 'path' => '/api/v1/campaigns/{campaign}/products/{product}/media', 'desc' => 'Listar multimedia del producto en campaña'],
            ['method' => 'POST', 'color' => $post, 'path' => '/api/v1/campaigns/{campaign}/products/{product}/media', 'desc' => 'Subir multimedia (multipart file o url)'],
            ['method' => 'PUT/PATCH', 'color' => $put, 'path' => '/api/v1/campaigns/{campaign}/products/{product}/media/{media}', 'desc' => 'Actualizar multimedia'],
            ['method' => 'DELETE', 'color' => $del, 'path' => '/api/v1/campaigns/{campaign}/products/{product}/media/{media}', 'desc' => 'Eliminar multimedia'],
        ];
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'api_token' => ['nullable', 'string', 'min:16', 'max:255'],
        ]);

        $token = trim((string) ($data['api_token'] ?? ''));
        if ($token !== '' && $token !== '********') {
            PlatformSetting::put('api.public_token', $token, 'api', true);
        }

        return back()->with('success', 'Token de API guardado.');
    }

    public function regenerate()
    {
        $token = bin2hex(random_bytes(24));
        PlatformSetting::put('api.public_token', $token, 'api', true);

        return back()
            ->with('success', 'Nuevo token de API generado. Cópialo ahora; no volverá a mostrarse.')
            ->with('new_api_token', $token);
    }

    public function disable()
    {
        PlatformSetting::query()->where('key', 'api.public_token')->delete();

        return back()->with('success', 'API desactivada. Si existe MULTIDROP_API_TOKEN en .env, seguirá vigente.');
    }

    public function test(Request $request)
    {
        $token = trim((string) PlatformSetting::getValue('api.public_token', config('multidrop.api_token', '')));

        if ($token === '') {
            return $this->respond($request, false, 'No hay token configurado. Genera uno o guarda un token.');
        }

        try {
            $probe = HttpRequest::create('/api/v1/stores', 'GET', ['per_page' => 20]);
            $probe->headers->set('Authorization', 'Bearer '.$token);
            $probe->headers->set('Accept', 'application/json');

            $response = app()->make(HttpKernel::class)->handle($probe);
            $payload = json_decode((string) $response->getContent(), true);
            $code = $response->getStatusCode();

            if ($code === 200) {
                $total = data_get($payload, 'data.pagination.total', '?');

                return $this->respond($request, true, 'API respondió HTTP 200 · '.$total.' tiendas accesibles.');
            }

            $msg = (string) data_get($payload, 'message', '');

            return $this->respond($request, false, 'La API respondió '.($msg !== '' ? $msg : ('HTTP '.$code)));
        } catch (\Throwable $e) {
            return $this->respond($request, false, 'La prueba falló: '.$e->getMessage());
        }
    }

    protected function respond(Request $request, bool $ok, string $message)
    {
        $payload = ['ok' => $ok, 'success' => $ok, 'message' => $message];

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($payload);
        }

        return $ok
            ? back()->with('success', $message)
            : back()->with('error', $message);
    }

    protected function mask(?string $token): ?string
    {
        if ($token === null || $token === '' || strlen($token) < 8) {
            return $token;
        }

        return substr($token, 0, 6).'••••••'.substr($token, -4);
    }
}