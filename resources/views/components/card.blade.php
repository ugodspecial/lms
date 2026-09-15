{{--
    Card — the standard raised surface.

    Every list, form and summary in all nine portal areas sits in one of these,
    so its padding, radius and border are decided once. `as` allows a semantic
    element (article, section, li) without changing the appearance.
--}}
@props([
    'as' => 'div',
    'padded' => true,
    'interactive' => false,
])

@php
    $classes = 'rounded-xl border border-default bg-[var(--surface-raised)] shadow-2xs';

    if ($padded) {
        $classes .= ' p-5 sm:p-6';
    }

    if ($interactive) {
        $classes .= ' transition hover:border-strong hover:shadow-sm';
    }
@endphp

<{{ $as }} {{ $attributes->merge(['class' => $classes]) }}>
    @isset($header)
        <div class="{{ $padded ? '-mx-5 -mt-5 mb-5 border-b border-default px-5 py-4 sm:-mx-6 sm:-mt-6 sm:px-6' : 'border-b border-default px-5 py-4' }}">
            {{ $header }}
        </div>
    @endisset

    {{ $slot }}

    @isset($footer)
        <div class="{{ $padded ? '-mx-5 -mb-5 mt-5 border-t border-default px-5 py-4 sm:-mx-6 sm:-mb-6 sm:px-6' : 'border-t border-default px-5 py-4' }}">
            {{ $footer }}
        </div>
    @endisset
</{{ $as }}>
