<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response (§64, §75, §101).
 *
 * Kept deliberately conservative so the app works on shared cPanel hosting:
 * no HTTP Strict-Transport-Security preload, no report-only endpoints that
 * would leak student data to a third party. HSTS is emitted only when the
 * request actually arrived over HTTPS — sending it over plain HTTP is
 * meaningless and, behind a misconfigured proxy, actively harmful.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Never let this platform be framed: clickjacking a "withdraw funds" or
        // "delete enrolment" button is a real attack on real money and records.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Stop browsers sniffing a download into an executable type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // A leaked referrer from the parent portal must not carry a session or
        // a student identifier to a third-party site.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict the powerful browser APIs this app does not use. Anything the
        // Meet/Zoom iframe needs is granted per-embed via `allow`, not globally.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(self), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=()'
        );

        // Baseline XSS posture. The full CSP with the Meet/Zoom frame sources is
        // Phase 12 work: a wrong CSP on day one silently breaks OAuth popups and
        // embedded meetings, which is worse than a documented, tested rollout.
        $response->headers->set('X-XSS-Protection', '0');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
