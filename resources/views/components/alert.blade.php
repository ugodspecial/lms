{{--
    Alert / notice.

    The tone drives colour AND the ARIA role: an assertive role on a success
    message would shout at the user, and a polite role on an error would let a
    failure go unnoticed. Both are accessibility defects, so they are coupled
    here and cannot be set inconsistently by a caller.
--}}
@props([
    'tone' => 'info',          // success | danger | warning | info
    'dismissible' => false,
    'title' => null,
])

@php
    $styles = match ($tone) {
        'success' => 'border-success-500/30 bg-success-50 text-success-900 dark:bg-success-900/20 dark:text-success-100',
        'danger' => 'border-danger-500/30 bg-danger-50 text-danger-900 dark:bg-danger-900/20 dark:text-danger-100',
        'warning' => 'border-warning-500/30 bg-warning-50 text-warning-900 dark:bg-warning-900/20 dark:text-warning-100',
        default => 'border-info-500/30 bg-info-50 text-info-900 dark:bg-info-900/20 dark:text-info-100',
    };

    $iconClass = match ($tone) {
        'success' => 'text-success-600 dark:text-success-500',
        'danger' => 'text-danger-600 dark:text-danger-500',
        'warning' => 'text-warning-600 dark:text-warning-500',
        default => 'text-info-600 dark:text-info-500',
    };

    $assertive = in_array($tone, ['danger', 'warning'], true);
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {$styles}"]) }}
     role="{{ $assertive ? 'alert' : 'status' }}"
     @if ($dismissible) data-dismissible @endif>
    <svg class="mt-0.5 h-5 w-5 shrink-0 {{ $iconClass }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        @if ($tone === 'success')
            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/>
        @elseif ($tone === 'danger')
            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm0-13a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
        @elseif ($tone === 'warning')
            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
        @else
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd"/>
        @endif
    </svg>

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="{{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>

    @if ($dismissible)
        <button type="button"
                data-dismiss
                aria-label="Dismiss notification"
                class="-m-1 shrink-0 rounded p-1 opacity-70 transition hover:opacity-100">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
            </svg>
        </button>
    @endif
</div>
