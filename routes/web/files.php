<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| File downloads — one route for every stored object
|--------------------------------------------------------------------------
|
| Deliberately a single route with no auth middleware, rather than a download
| route per feature. Every stored object on the platform is in the `files`
| registry with a visibility tier, and one entry point means the checks in
| DownloadAuthorizer cannot be bypassed by a feature that forgot to add them
| (§40, §59, ADR-10).
|
| The alternative — a public disk and a guessable path — is what this replaces.
| Only the `public` tier is symlinked into the document root; the other three
| tiers have `serve => false`, so there is no URL that returns their bytes
| except this one, and it asks first.
|
| `whereUuid` matters: the row id is sequential and must never be a route
| parameter, or `/files/1/download`, `/files/2/download` is a listing of
| everything the platform holds.
|
*/

use App\Http\Controllers\Files\FileDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/files/{uuid}/download', FileDownloadController::class)
    ->whereUuid('uuid')
    ->name('files.download');
