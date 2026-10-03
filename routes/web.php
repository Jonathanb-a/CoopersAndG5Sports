<?php

use App\Http\Middleware\ResolveStore;
use App\Models\Product;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(ResolveStore::class)->group(function () {
    Route::get('/', function () {
        return Inertia::render('home', [
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'price']),
        ]);
    })->name('home');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
