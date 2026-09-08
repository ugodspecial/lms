<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public routes — no authentication
|--------------------------------------------------------------------------
|
| Phase 0 ships the home page only. Every additional public page (catalogue,
| blog, legal) is added by the phase that makes it real: a catalogue link that
| 404s is a dead button, and the platform does not have dead buttons (§63).
|
*/

use App\Http\Controllers\Site\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
