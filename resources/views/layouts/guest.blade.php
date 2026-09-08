@extends('layouts.base')

{{--
    Guest shell: sign in, register, verify email, reset password (§20–§23).

    A single narrow column, no portal navigation. Deliberately free of anything
    that competes with the form: a person resetting a password at 11pm before an
    exam does not need a marketing footer.
--}}

@section('body')
    <div class="flex min-h-screen flex-col bg-[var(--surface-page)]">
        <header class="flex h-16 shrink-0 items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 rounded-md">
                <x-brand-mark size="sm" />
                <span class="text-sm font-semibold text-[var(--text-primary)]">
                    {{ config('platform.name', 'EduPlatform') }}
                </span>
            </a>

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
        </header>

        <main id="main" class="flex flex-1 items-center justify-center px-4 py-10 sm:px-6">
            <div class="w-full max-w-md">
                @yield('content')
            </div>
        </main>
    </div>
@endsection
