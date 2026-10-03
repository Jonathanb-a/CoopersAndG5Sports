<?php

use App\Http\Controllers\StoreHomeController;
use App\Http\Middleware\ResolveStore;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(ResolveStore::class)->group(function () {
    Route::get('/', StoreHomeController::class)->name('home');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
