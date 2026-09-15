{{--
    Platform brand mark.

    An original mark — three ascending steps under a rising arc: a learner's
    progression through a programme. It inherits `currentColor` so it works on
    any surface and in both themes without a second asset.
--}}
@props(['size' => 'md'])

@php
    $dimensions = match ($size) {
        'sm' => 'h-5 w-5',
        'md' => 'h-8 w-8',
        'lg' => 'h-12 w-12',
        'xl' => 'h-16 w-16',
        default => $size,
    };
@endphp

<svg {{ $attributes->merge(['class' => $dimensions]) }} viewBox="0 0 32 32" fill="none"
     role="img" aria-label="{{ config('platform.name', 'EduPlatform') }} logo">
    <rect x="0.75" y="0.75" width="30.5" height="30.5" rx="8"
          class="fill-brand-600 dark:fill-brand-500"/>
    <path d="M7 22.5c2.6-6.2 7.3-9.5 13-9.9"
          class="stroke-white/85" stroke-width="2" stroke-linecap="round"/>
    <rect x="8" y="20" width="3.2" height="5" rx="1.1" class="fill-white/70"/>
    <rect x="14.4" y="16.5" width="3.2" height="8.5" rx="1.1" class="fill-white/85"/>
    <rect x="20.8" y="13" width="3.2" height="12" rx="1.1" class="fill-white"/>
    <circle cx="21.4" cy="9.2" r="2.1" class="fill-accent-300"/>
</svg>
