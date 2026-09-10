<?php

namespace App\Http\Controllers;

class PwaController extends Controller
{
    /**
     * Per-tenant manifest — name/icons come from the company profile (each
     * install is a different business). Icons are the actual generated
     * 192/512 PNGs (see FaviconGenerator, run whenever the logo/favicon is
     * saved) rather than one arbitrary-sized image reused at every
     * declared size, so the install prompt gets correctly-sized artwork.
     */
    public function manifest()
    {
        $company = company();
        $sizes = $company?->favicon_sizes ?? [];
        $name = $company?->title ?: 'BMS POS';

        $fallback = $company?->favicon ? '/' . $company->favicon : ($company?->logo ? '/' . $company->logo : '/favicon.ico');
        $icon192 = isset($sizes['192']) ? '/' . $sizes['192'] : $fallback;
        $icon512 = isset($sizes['512']) ? '/' . $sizes['512'] : $fallback;

        return response()->json([
            'name' => $name,
            'short_name' => \Illuminate\Support\Str::limit($name, 12, ''),
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#0ea5e9',
            'icons' => [
                ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }
}
