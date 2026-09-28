<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

// Browser security headers on every web response (roadmap 4.12):
//  - Content-Security-Policy: scripts only from this site (the one inline script in app.blade.php
//    carries a per-request nonce), no plugins, no framing by other sites
//  - HSTS on HTTPS, nosniff, same-origin framing, a strict referrer policy, no camera/mic/geolocation
// The CSP is left off where a page brings its own inline scripts (Horizon, the license pages) and
// while the Vite dev server runs. config('isp.csp'): enforce (default) | report | off.
class SecurityHeaders
{
    private const CSP_SKIP = ['horizon', 'horizon/*', 'license', 'license/*', 'subscription', 'subscription/*', 'terms'];

    public function handle(Request $request, Closure $next)
    {
        $mode = config('isp.csp', 'enforce');
        if ($mode !== 'off') {
            Vite::useCspNonce(); // app.blade.php and @vite tags read it
        }

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $isHtml = str_contains((string) $headers->get('Content-Type'), 'text/html');
        if ($mode !== 'off' && $isHtml && ! $request->is(...self::CSP_SKIP) && ! Vite::isRunningHot()) {
            $headers->set($mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', self::policy(Vite::cspNonce()));
        }
        return $response;
    }

    public static function policy(?string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'" . ($nonce ? " 'nonce-{$nonce}'" : ''),
            // Vue and the editor set inline styles
            "style-src 'self' 'unsafe-inline'",
            // QR codes and previews are data:/blob: URLs; package / company logos may be remote images
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
