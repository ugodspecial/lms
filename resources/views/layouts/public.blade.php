@extends('layouts.base')

@section('body')
    <div class="flex min-h-screen flex-col">
        <x-public.header />

        <main id="main" class="flex-1">
            @yield('content')
        </main>

        <x-public.footer />
    </div>
@endsection
