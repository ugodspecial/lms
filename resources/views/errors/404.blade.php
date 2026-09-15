{{-- Not found. Same enumeration rule as 403: never confirm whether a record exists. --}}
@extends('layouts.base')

@section('title', 'Page not found')

@section('body')
    <x-error-page
        code="404"
        title="We could not find that page"
        message="The link may be out of date, or the record may have been removed. Nothing was changed by this request."
        mailSubject="Missing page (404)"
    />
@endsection
