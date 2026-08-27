<?php

namespace App\Support;

use Illuminate\Http\Request;

class VisitDevice
{
    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public static function fromRequest(?Request $request = null): string
    {
        try {
            $request ??= request();
        } catch (\Throwable) {
            return self::DESKTOP;
        }

        if (! $request instanceof Request) {
            return self::DESKTOP;
        }

        $override = strtolower(trim((string) $request->query('md_device', '')));
        if ($override === self::MOBILE || $override === self::DESKTOP) {
            return $override;
        }

        $ua = (string) $request->userAgent();
        if ($ua !== '' && preg_match(
            '/iPhone|iPod|Android.+Mobile|webOS|BlackBerry|IEMobile|Opera Mini|TikTok|ByteDance|Instagram|FBAN|FBAV|Twitter/i',
            $ua
        )) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }
}
