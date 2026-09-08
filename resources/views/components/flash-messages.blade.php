{{--
    Session flash notices.

    Rendered from the base layout so no page can forget them. Four tones only,
    matching the semantic status tokens: success, danger, warning, info.

    `role="status"` (polite) for success/info and `role="alert"` (assertive) for
    danger/warning — a screen-reader user must be told a payment failed
    immediately, but should not be interrupted to hear that a profile saved.
--}}
@php
    $messages = collect([
        'success' => session('success'),
        'danger' => session('error') ?? session('danger'),
        'warning' => session('warning'),
        'info' => session('info') ?? session('status'),
    ])->filter()->all();
@endphp

@if ($messages !== [])
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 mx-auto flex w-full max-w-xl flex-col gap-2 px-4">
        @foreach ($messages as $tone => $message)
            <x-alert :tone="$tone" dismissible class="pointer-events-auto">
                {{ $message }}
            </x-alert>
        @endforeach
    </div>
@endif
