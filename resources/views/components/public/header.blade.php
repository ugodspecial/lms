{{--
    Public header.

    Contains only links that resolve. The nav is built from
    config('platform.modules') filtered to modules whose phase is <= the
    platform's current phase, so an unbuilt module never appears as a dead link
    (§63). In Phase 0 nothing but the home route exists, so the nav is the
    brand mark plus the theme control — which is honest, not incomplete.
--}}
<header class="sticky top-0 z-40 border-b border-default bg-[var(--surface-raised)]/90 backdrop-blur">
    <div class="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-md">
            <x-brand-mark class="h-8 w-8" />
            <span class="text-base font-semibold tracking-tight text-[var(--text-primary)]">
                {{ config('platform.name', 'EduPlatform') }}
            </span>
        </a>

        <div class="flex items-center gap-2">
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
        </div>
    </div>
</header>
