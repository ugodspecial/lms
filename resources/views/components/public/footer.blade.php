{{--
    Public footer.

    Legal pages (privacy, terms, refunds) arrive in Phase 11 with real content
    written against the actual data-retention policy. Linking to an empty page
    now would misrepresent the platform's compliance posture (§63, §98).
--}}
<footer class="border-t border-default bg-[var(--surface-raised)]">
    <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
            <p class="text-sm text-[var(--text-muted)]">
                &copy; {{ now()->year }} {{ config('platform.name', 'EduPlatform') }}.
            </p>

            <p class="text-sm text-[var(--text-muted)]">
                Support:
                <a href="mailto:{{ config('platform.support.email') }}"
                   class="rounded font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                    {{ config('platform.support.email') }}
                </a>
            </p>
        </div>
    </div>
</footer>
