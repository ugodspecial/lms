{{--
    Button / link-button.

    One component for both so a call-to-action looks identical whether it
    submits a form or navigates. `href` decides the rendered element — an
    <a> styled as a button is still an <a>, which keeps middle-click,
    open-in-new-tab and screen-reader semantics correct.

    `type` defaults to "button", not the HTML default of "submit": an accidental
    submit inside a form is a data-integrity bug, and the explicit opt-in is
    cheap.
--}}
@props([
    'variant' => 'primary',   // primary | secondary | ghost | danger
    'size' => 'md',           // sm | md | lg
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 focus-visible:outline-brand-600',
        'secondary' => 'border border-default bg-[var(--surface-raised)] text-[var(--text-primary)] hover:bg-[var(--surface-sunken)] hover:border-strong',
        'ghost' => 'text-[var(--text-secondary)] hover:bg-[var(--surface-sunken)] hover:text-[var(--text-primary)]',
        'danger' => 'bg-danger-600 text-white hover:bg-danger-700 active:bg-danger-700 focus-visible:outline-danger-600',
    ];

    $sizes = [
        'sm' => 'px-2.5 py-1.5 text-xs',
        'md' => 'px-3.5 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-base',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition '.
        ($variants[$variant] ?? $variants['primary']).' '.
        ($sizes[$size] ?? $sizes['md']);

    if ($disabled) {
        // `opacity` + `pointer-events-none` rather than removing the handler: a
        // disabled control still needs to be visible and still needs to explain
        // itself via aria-disabled.
        $classes .= ' pointer-events-none opacity-50';
    }
@endphp

@if ($href)
    <a href="{{ $href }}"
       {{ $attributes->merge(['class' => $classes]) }}
       @if ($disabled) aria-disabled="true" tabindex="-1" @endif>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}"
            {{ $attributes->merge(['class' => $classes]) }}
            @disabled($disabled)
            @if ($disabled) aria-disabled="true" @endif>
        {{ $slot }}
    </button>
@endif
