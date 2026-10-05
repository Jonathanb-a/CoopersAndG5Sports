<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\AddressController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StoreHomeController;
use App\Http\Middleware\ResolveStore;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveStore::class)->group(function () {
    Route::get('/', StoreHomeController::class)->name('home');
    Route::get('catalogo', CatalogController::class)->name('catalog');
    Route::get('categoria/{slug}', CatalogController::class)->name('category');
    Route::get('producto/{slug}', ProductController::class)->name('product');

    // Carrito en la sesión (no pide cuenta). Solo recibe ids y cantidades.
    Route::get('carrito', [CartController::class, 'show'])->name('cart.show');
    Route::post('carrito', [CartController::class, 'store'])->name('cart.store');
    Route::delete('carrito', [CartController::class, 'clear'])->name('cart.clear');
    Route::patch('carrito/{variant}', [CartController::class, 'update'])->whereNumber('variant')->name('cart.update');
    Route::delete('carrito/{variant}', [CartController::class, 'destroy'])->whereNumber('variant')->name('cart.destroy');
    Route::post('carrito/cupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
    Route::delete('carrito/cupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

    // Compra: exige cuenta. La pantalla llega en la tarea 15; mientras tanto solo manda a iniciar sesión.
    Route::get('checkout', fn () => abort(404))->middleware('auth:customer')->name('checkout');

    // Cuentas de clientes (guard "customer"). La sesión es por dominio: se inicia en cada tienda.
    Route::middleware('guest:customer')->group(function () {
        Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('register', [RegisteredUserController::class, 'store']);

        Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('login', [AuthenticatedSessionController::class, 'store']);

        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
        Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');

        Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
        Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
    });

    Route::middleware('auth:customer')->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

        Route::get('mi-cuenta', [AccountController::class, 'show'])->name('account.show');
        Route::patch('mi-cuenta', [AccountController::class, 'update'])->name('account.update');
        Route::put('mi-cuenta/contrasena', [AccountController::class, 'updatePassword'])->name('account.password');

        Route::post('mi-cuenta/direcciones', [AddressController::class, 'store'])->name('addresses.store');
        Route::put('mi-cuenta/direcciones/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('addresses.update');
        Route::delete('mi-cuenta/direcciones/{address}', [AddressController::class, 'destroy'])->whereNumber('address')->name('addresses.destroy');
    });
});
