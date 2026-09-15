{{--
    Server error. No internals, ever (§76).

    A reference id is generated here and logged alongside the real exception, so
    support can find the stack trace from the six characters a user reads out —
    without the stack trace itself ever reaching the browser.
--}}
@php
    $reference = 'err_'.substr(bin2hex(random_bytes(6)), 0, 12);

    \Illuminate\Support\Facades\Log::error('Unhandled error rendered to user', [
        'reference' => $reference,
        'path' => request()->path(),
        'method' => request()->method(),
        'user_id' => request()->user()?->getAuthIdentifier(),
        'ip' => request()->ip(),
    ]);
@endphp
@extends('layouts.base')

@section('title', 'Something went wrong')

@section('body')
    <x-error-page
        code="500"
        title="Something went wrong on our side"
        message="The request could not be completed. It has been logged, and no data was changed as a result of this error."
        details="If you were in the middle of a payment, do not pay again — check your order history first, and quote the reference below if you contact support."
        :reference="$reference"
        :mailSubject="'Server error (500) ref '.$reference"
    />
@endsection
