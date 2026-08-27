<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Suppliers\Cj\CjVideoProxy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CjMediaController extends Controller
{
    public function video(Request $request, CjVideoProxy $proxy)
    {
        return $proxy->stream(trim((string) $request->query('u', '')));
    }
}
