<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Route;

/**
 * Public landing page.
 *
 * Invokable: one route, one action, no resource to resolve.
 *
 * The page reports what THIS deployment can actually do. Module availability is
 * derived from config('platform.modules') and from which named routes really
 * exist, so a module is never advertised ahead of the phase that builds it —
 * a landing page full of links that 404 is the exact failure mode §63 forbids.
 */
final class HomeController extends Controller
{
    public function __invoke(): Renderable
    {
        return view('home', [
            'platformName' => (string) config('platform.name', 'EduPlatform'),
            'modules' => $this->modules(),
            'environment' => app()->environment(),
            'apiEnabled' => (bool) config('platform.api.enabled', false),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, phase: int, live: bool, url: ?string}>
     */
    private function modules(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = (array) config('platform.modules', []);

        $modules = [];

        foreach ($configured as $key => $module) {
            $routeName = match ($key) {
                'identity' => 'login',
                'education' => 'admin.dashboard',
                'lms' => 'student.dashboard',
                'tutoring' => 'tutor.dashboard',
                'assessment' => 'evaluator.dashboard',
                'communication' => 'admin.dashboard',
                'commerce' => 'admin.dashboard',
                'integration' => 'admin.dashboard',
                'administration' => 'admin.dashboard',
                default => null,
            };

            // "Live" means a real, registered route — not a feature flag an
            // operator forgot to switch off.
            $live = $routeName !== null && Route::has($routeName);

            $modules[] = [
                'key' => $key,
                'label' => (string) ($module['label'] ?? $key),
                'phase' => (int) ($module['phase'] ?? 0),
                'live' => $live,
                'url' => $live ? route((string) $routeName) : null,
            ];
        }

        return $modules;
    }
}
