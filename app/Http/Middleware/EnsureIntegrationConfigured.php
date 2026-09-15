<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\PlatformException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block a route until the integration it depends on is genuinely configured.
 *
 * The platform must never render a "Join meeting" button that produces an error
 * (§63, §67). Rather than hiding a whole module when one credential is missing,
 * the route declares what it needs and this middleware refuses cleanly — with an
 * administrator-actionable message pointing at the setup docs, not a stack
 * trace.
 *
 * The definition of "configured" lives in config/platform.php `integrations`,
 * which is the same list `php artisan platform:doctor` reports on. One source of
 * truth means the guard, the UI and the operator's checklist cannot drift apart.
 *
 * Usage:  ->middleware('integration.connected:zoom')
 *         ->middleware('integration.connected:google,paystack')
 */
final class EnsureIntegrationConfigured
{
    /**
     * Values shipped in .env.example or used by the test suite, so a copied file
     * is never mistaken for a live setup. A key that is *present* but still a
     * placeholder is the most dangerous state: everything looks configured, the
     * UI renders the button, and the call fails at the provider.
     */
    private const PLACEHOLDER_TOKENS = [
        'your-', 'changeme', 'xxxxxxxx', 'placeholder', 'replace_me', 'replace-me',
        'fake_for_ci', 'example.test', 'dummy', 'null',
    ];

    public function handle(Request $request, Closure $next, string ...$integrations): Response
    {
        foreach ($integrations as $integration) {
            $missing = $this->missingRequirements($integration);

            if ($missing !== null) {
                throw new PlatformException(
                    message: "{$missing['label']} is not configured yet. ".
                        "An administrator must set {$missing['hint']} before this can be used.",
                    errorCode: "integration.{$integration}.not_configured",
                    statusCode: 503,
                    context: [
                        'integration' => $integration,
                        'missing' => $missing['keys'],
                        'docs' => $missing['docs'],
                    ],
                );
            }
        }

        return $next($request);
    }

    /**
     * Describe what an integration is missing.
     *
     * Returns null when the integration is fully configured and enabled, which
     * is the signal for handle() to let the request through.
     *
     * @return array{label: string, keys: list<string>, hint: string, docs: string}|null
     */
    public function missingRequirements(string $integration): ?array
    {
        /** @var array<string, mixed>|null $config */
        $config = config("platform.integrations.{$integration}");

        if (! is_array($config)) {
            // An unknown name is a developer error, not a missing credential:
            // fail loudly in every environment rather than silently allowing.
            throw new PlatformException(
                message: "Unknown integration [{$integration}].",
                errorCode: 'integration.unknown',
                statusCode: 500,
            );
        }

        $label = (string) ($config['label'] ?? $integration);
        $docs = (string) ($config['docs'] ?? 'SETUP.md');

        if (! ($config['enabled'] ?? false)) {
            return ['label' => $label, 'keys' => [], 'hint' => 'the integration to be enabled', 'docs' => $docs];
        }

        $missing = [];

        foreach ((array) ($config['required'] ?? []) as $key) {
            $value = config((string) $key);

            if (! is_string($value) || trim($value) === '' || $this->isPlaceholder($value)) {
                $missing[] = (string) $key;
            }
        }

        if ($missing === []) {
            return null;
        }

        return [
            'label' => $label,
            'keys' => $missing,
            'hint' => implode(', ', array_map(fn (string $k): string => strtoupper(str_replace('.', '_', $k)), $missing)),
            'docs' => $docs,
        ];
    }

    private function isPlaceholder(string $value): bool
    {
        $lower = strtolower($value);

        foreach (self::PLACEHOLDER_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }
}
