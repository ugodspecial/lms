{{--
    Service unavailable.

    Two distinct causes reach this page: planned maintenance, and an integration
    that is not configured (EnsureIntegrationConfigured throws a 503
    PlatformException). The wording therefore points at configuration rather than
    promising a maintenance window that may not exist.
--}}
@extends('layouts.base')

@section('title', 'Service unavailable')

@section('body')
    <x-error-page
        code="503"
        title="This service is not available right now"
        message="Either the platform is under maintenance, or an external service this feature depends on has not been connected yet."
        details="An administrator can confirm the cause with: php artisan platform:doctor"
        mailSubject="Service unavailable (503)"
    />
@endsection
