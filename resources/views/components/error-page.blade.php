{{--
    Shared HTTP error page body (§76).

    Rendered from resources/views/errors/*.blade.php, which Laravel discovers
    automatically for any HttpException whose status matches the file name.

    What is deliberately absent: the exception message, the file and line, and
    any query text. A 500 page that echoes "SQLSTATE[23000] ... duplicate entry
    for key 'parent_student_user_id_unique'" tells an attacker exactly which
    constraint to probe next, and tells a parent nothing useful (§76, §98).

    Under APP_DEBUG the framework's detailed page takes precedence, so a
    developer still gets the stack trace they need.
--}}
@props([
    'code',
    'title',
    'message',
    'details' => null,
    'primaryUrl' => null,
    'primaryLabel' => 'Back to home',
    'reference' => null,
    'mailSubject' => null,
])

<main id="main" class="flex min-h-screen items-center justify-center px-4 py-16">
    <div class="w-full max-w-lg text-center">
        <p class="font-mono text-6xl font-bold tracking-tight text-brand-600 dark:text-brand-400">
            {{ $code }}
        </p>

        <h1 class="mt-4 text-2xl font-semibold tracking-tight text-[var(--text-primary)]">
            {{ $title }}
        </h1>

        <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-[var(--text-secondary)]">
            {{ $message }}
        </p>

        @if ($details)
            <p class="mx-auto mt-2 max-w-md text-xs leading-relaxed text-[var(--text-muted)]">
                {{ $details }}
            </p>
        @endif

        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <x-button :href="$primaryUrl ?? url('/')" variant="primary">
                {{ $primaryLabel }}
            </x-button>

            @if (config('platform.support.email'))
                {{-- Pre-filled subject so support can triage without asking what
                     the user was doing. Never carries a session or record id. --}}
                <x-button :href="'mailto:'.config('platform.support.email').($mailSubject ? '?subject='.rawurlencode($mailSubject) : '')"
                          variant="secondary">
                    Contact support
                </x-button>
            @endif
        </div>

        @if ($reference)
            {{--
                A support reference lets an operator find the incident in the log
                without the user having to describe or screenshot anything. The
                underlying error is logged server-side with the same reference.
            --}}
            <p class="mt-8 font-mono text-2xs text-[var(--text-muted)]">
                Reference: {{ $reference }}
            </p>
        @endif
    </div>
</main>
