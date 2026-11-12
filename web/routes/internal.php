<?php

use App\Http\Controllers\Internal\LogIngestController;
use App\Http\Controllers\Internal\SnapshotController;
use App\Http\Controllers\Internal\StatusReportController;
use Illuminate\Support\Facades\Route;

// Called by the tunnel server, never by browsers: no session, no cookies.
Route::get('snapshot', SnapshotController::class)->name('internal.snapshot');
Route::post('logs', LogIngestController::class)->name('internal.logs');
Route::post('status', StatusReportController::class)->name('internal.status');
