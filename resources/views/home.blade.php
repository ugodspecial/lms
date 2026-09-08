@extends('layouts.public')

@section('title', config('platform.name', 'EduPlatform'))
@section('description', 'A modular online education platform: admissions, learning, tutoring, assessment and commerce in one Laravel application.')

@section('content')
    {{--
        Phase 0 landing page.

        It states what this deployment can do right now, and nothing more. As
        each phase lands, its module becomes a real link and the status changes
        from "planned" to "live" — driven by Route::has(), so the page cannot
        drift away from reality (§63).
    --}}

    <section class="border-b border-default">
        <div class="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
            <div class="max-w-3xl">
                <x-badge tone="brand" dot>
                    Phase 0 — platform foundation
                </x-badge>

                <h1 class="mt-5 text-4xl font-bold tracking-tight text-[var(--text-primary)] sm:text-5xl">
                    {{ $platformName }}
                </h1>

                <p class="mt-5 text-lg leading-relaxed text-[var(--text-secondary)]">
                    A modular online education platform. Admissions, learning, tutoring,
                    assessment, meetings and commerce — one Laravel application, one
                    database, deployable on conventional shared hosting.
                </p>

                <dl class="mt-10 grid grid-cols-2 gap-x-6 gap-y-6 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Environment</dt>
                        <dd class="mt-1">
                            <x-badge :tone="$environment === 'production' ? 'success' : 'warning'">
                                {{ $environment }}
                            </x-badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Laravel</dt>
                        <dd class="mt-1 text-sm font-semibold text-[var(--text-primary)]">{{ app()->version() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">PHP</dt>
                        <dd class="mt-1 text-sm font-semibold text-[var(--text-primary)]">{{ PHP_VERSION }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">API v1</dt>
                        <dd class="mt-1">
                            <x-badge :tone="$apiEnabled ? 'success' : 'neutral'" :dot="$apiEnabled">
                                {{ $apiEnabled ? 'enabled' : 'not enabled' }}
                            </x-badge>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    <section class="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-[var(--text-primary)]">Delivery modules</h2>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--text-secondary)]">
                    Each module is delivered in a controlled phase and only becomes
                    navigable when its routes, permissions and tests are real.
                </p>
            </div>
            <div class="flex items-center gap-4 text-xs text-[var(--text-muted)]">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span> live
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full bg-neutral-300 dark:bg-neutral-600"></span> planned
                </span>
            </div>
        </div>

        <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($modules as $module)
                <li>
                    <x-card class="h-full">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">{{ $module['label'] }}</h3>
                            <x-badge :tone="$module['live'] ? 'success' : 'neutral'" :dot="$module['live']">
                                {{ $module['live'] ? 'live' : 'phase ' . $module['phase'] }}
                            </x-badge>
                        </div>

                        <p class="mt-2 font-mono text-2xs uppercase tracking-wide text-[var(--text-muted)]">
                            {{ $module['key'] }}
                        </p>

                        @if ($module['live'] && $module['url'])
                            <x-button :href="$module['url']" variant="ghost" size="sm" class="mt-4 -ml-2">
                                Open
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/>
                                </svg>
                            </x-button>
                        @endif
                    </x-card>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="border-t border-default bg-[var(--surface-raised)]">
        <div class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-[var(--text-muted)]">Operator endpoints</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <x-card>
                    <h3 class="text-sm font-semibold text-[var(--text-primary)]">Health check</h3>
                    <p class="mt-1.5 text-sm text-[var(--text-secondary)]">
                        Liveness probe used by the scheduler and by uptime monitoring.
                    </p>
                    <a href="{{ url('/up') }}"
                       class="mt-3 inline-block rounded font-mono text-xs text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                        GET /up
                    </a>
                </x-card>

                <x-card>
                    <h3 class="text-sm font-semibold text-[var(--text-primary)]">API status</h3>
                    <p class="mt-1.5 text-sm text-[var(--text-secondary)]">
                        Unauthenticated version probe for API clients.
                    </p>
                    <a href="{{ url('/api/v1/status') }}"
                       class="mt-3 inline-block rounded font-mono text-xs text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                        GET /api/v1/status
                    </a>
                </x-card>
            </div>

            <p class="mt-8 text-sm leading-relaxed text-[var(--text-secondary)]">
                Deployment readiness is verified with
                <code class="rounded bg-[var(--surface-sunken)] px-1.5 py-0.5 font-mono text-xs text-[var(--text-primary)]">php artisan platform:doctor</code>,
                which reports PHP version and extensions, database connectivity, storage
                writability, queue and scheduler configuration, and every integration
                that is not yet configured.
            </p>
        </div>
    </section>
@endsection
