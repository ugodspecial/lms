{{--
    CSRF failure / expired session.

    This is the page a parent sees after leaving a long enrolment form open
    overnight, so it must state plainly that the work was NOT saved. The
    comfortable assumption — "it probably went through" — is what produces
    duplicate applications and duplicate payments.
--}}
@extends('layouts.base')

@section('title', 'Session expired')

@section('body')
    <x-error-page
        code="419"
        title="Your session expired"
        message="For your security this page was signed out before the form could be submitted. Nothing you entered was saved."
        details="Go back, re-enter the details and submit again. If this keeps happening, your browser may be blocking cookies for this site."
        mailSubject="Session expired (419)"
    />
@endsection
