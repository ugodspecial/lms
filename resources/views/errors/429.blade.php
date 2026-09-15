{{--
    Rate limited. Saying a limit exists is fine; stating the exact threshold and
    decay window would let an attacker tune a brute-force attempt to sit just
    under it, so the wording stays general (§75).
--}}
@extends('layouts.base')

@section('title', 'Too many requests')

@section('body')
    <x-error-page
        code="429"
        title="Too many requests"
        message="You have made too many requests in a short time. Please wait a moment and try again."
        details="This limit protects accounts from automated sign-in attempts and keeps shared third-party quotas available to everyone."
        mailSubject="Rate limited (429)"
    />
@endsection
