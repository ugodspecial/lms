{{--
    Badge / status pill.

    Tone is tied to meaning, and the mapping is fixed here so "paid" can never
    be rendered red in one screen and green in another (§63). Callers pass a
    semantic tone, never a colour.
--}}
@props([
    'tone' => 'neutral',   // neutral | brand | accent | success | warning | danger | info
    'dot' => false,
])

@php
    $classes = match ($tone) {
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-900/30 dark:text-brand-200 dark:ring-brand-400/20',
        'accent' => 'bg-accent-50 text-accent-700 ring-accent-600/20 dark:bg-accent-900/30 dark:text-accent-200 dark:ring-accent-400/20',
        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-900/30 dark:text-success-100 dark:ring-success-500/20',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-900/30 dark:text-warning-100 dark:ring-warning-500/20',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-900/30 dark:text-danger-100 dark:ring-danger-500/20',
        'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-900/30 dark:text-info-100 dark:ring-info-500/20',
        default => 'bg-neutral-100 text-neutral-700 ring-neutral-500/15 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-400/15',
    };

    $dotClasses = match ($tone) {
        'brand' => 'bg-brand-500',
        'accent' => 'bg-accent-500',
        'success' => 'bg-success-500',
        'warning' => 'bg-warning-500',
        'danger' => 'bg-danger-500',
        'info' => 'bg-info-500',
        default => 'bg-neutral-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dotClasses }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
