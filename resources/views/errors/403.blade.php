{{--
    Forbidden: the person is known, but may not do this (§71, §76).

    The wording must NOT reveal whether the underlying resource exists. "You do
    not have access to this student's records" and "This student does not exist"
    are different facts, and telling a caller which one they hit turns a 403 into
    an enumeration oracle over student records.
--}}
@extends('layouts.base')

@section('title', 'Access denied')

@section('body')
    <x-error-page
        code="403"
        title="You do not have access to this"
        message="Your account does not have permission for the page or action you requested. If you believe you should, an administrator can review your access."
        details="A parent or guardian sees only the students linked to their own account; staff see only what their assigned permissions allow."
        mailSubject="Access request (403)"
    />
@endsection
