@extends('layouts.base')

{{--
    Authenticated portal shell (admin, parent, student, tutor, evaluator).

    One shell for all five areas, with the sidebar contents and the area label
    supplied by the including view or by a View Composer in Phase 1. Sharing the
    shell is deliberate: a parent who is also a tutor switches area without the
    interface changing shape under them, and a keyboard shortcut learned in one
    area works in all of them.

    Expected data (Phase 1):
      $area      — key from config('platform.areas')
      $user      — authenticated user
      $navigation— resolved, permission-filtered navigation items
--}}

@section('body')
    <div class="min-h-screen lg:grid lg:grid-cols-[var(--spacing-sidebar)_1fr]">
        <aside class="hidden border-r border-default bg-[var(--surface-raised)] lg:block"
               aria-label="{{ isset($area) ? ucfirst((string) $area) : 'Portal' }} navigation">
            <div class="sticky top-0 flex h-screen flex-col">
                <div class="flex h-16 shrink-0 items-center gap-3 border-b border-default px-5">
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5 rounded-md">
                        <x-brand-mark size="sm" />
                        <span class="truncate text-sm font-semibold text-[var(--text-primary)]">
                            {{ config('platform.name', 'EduPlatform') }}
                        </span>
                    </a>
                </div>

                <nav class="flex-1 overflow-y-auto px-3 py-4">
                    {{-- Navigation items are injected by the area's own view/composer
                         and are permission-filtered there (ADR-14). Rendering them
                         here would put authorization logic in a template. --}}
                    @yield('navigation')
                </nav>

                <div class="shrink-0 border-t border-default p-3">
                    @yield('sidebar-footer')
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-col">
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-default bg-[var(--surface-raised)]/90 px-4 backdrop-blur sm:px-6">
                <div class="min-w-0">
                    @yield('header', '')
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button type="button"
                            data-theme-toggle
                            aria-label="Change colour theme"
                            title="Change colour theme"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-default text-[var(--text-secondary)] transition hover:border-strong hover:text-[var(--text-primary)]">
                        <svg class="h-4 w-4 dark:hidden" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10 2a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 2Zm0 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm8-5a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 18 10Zm-13 0a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 5 10Zm5 8a.75.75 0 0 1-.75-.75v-1.5a.75.75 0 0 1 1.5 0v1.5A.75.75 0 0 1 10 18Zm4.95-13.536a.75.75 0 0 1 0 1.061l-1.061 1.06a.75.75 0 1 1-1.06-1.06l1.06-1.06a.75.75 0 0 1 1.061 0Zm-8.486 8.486a.75.75 0 0 1 0 1.06l-1.06 1.061a.75.75 0 1 1-1.061-1.06l1.06-1.061a.75.75 0 0 1 1.061 0Zm9.546 1.06a.75.75 0 0 1-1.06 0l-1.061-1.06a.75.75 0 1 1 1.06-1.061l1.061 1.06a.75.75 0 0 1 0 1.061Zm-8.485-8.485a.75.75 0 0 1-1.061 0l-1.06-1.061a.75.75 0 1 1 1.06-1.06l1.061 1.06a.75.75 0 0 1 0 1.061Z"/>
                        </svg>
                        <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.455 2.004a.75.75 0 0 1 .26.77 7 7 0 0 0 9.958 7.967.75.75 0 0 1 1.067.853A8.5 8.5 0 1 1 6.647 1.921a.75.75 0 0 1 .808.083Z" clip-rule="evenodd"/>
                        </svg>
                    </button>

                    @yield('header-actions')
                </div>
            </header>

            <main id="main" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <div class="mx-auto w-full max-w-7xl">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
@endsection
