<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\InventoryController;
use App\Http\Middleware\Admin;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:6,1')->name('setup');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Catalog and personal pantry
    Route::get('/', [InventoryController::class, 'index'])->name('catalog');
    Route::get('/pantry', [InventoryController::class, 'index'])->name('pantry');
    Route::post('/pantry/restock', [InventoryController::class, 'restock'])->name('pantry.restock');
    Route::post('/pantry/{product}', [InventoryController::class, 'track'])->name('pantry.track');
    Route::put('/pantry/{product}', [InventoryController::class, 'saveStock'])->name('pantry.save');
    Route::post('/pantry/{product}/adjust', [InventoryController::class, 'adjust'])->name('pantry.adjust');
    Route::delete('/pantry/{product}', [InventoryController::class, 'untrack'])->name('pantry.remove');

    // Shopping list
    Route::get('/shopping-list', [CartController::class, 'index'])->name('cart');
    Route::post('/shopping-list/checkout', [CartController::class, 'checkout'])->name('cart.checkout');
    Route::post('/shopping-list/product/{product}', [CartController::class, 'add'])->name('cart.add');
    Route::patch('/shopping-list/{item}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/shopping-list/{item}', [CartController::class, 'destroy'])->name('cart.remove');

    // Shared catalog management
    Route::prefix('admin')->middleware(Admin::class)->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/status', [AdminController::class, 'status'])->name('status');
        Route::post('/import', [AdminController::class, 'import'])->name('import');
        Route::post('/images/retry', [AdminController::class, 'retryImages'])->name('images.retry');
        Route::get('/products/create', [AdminController::class, 'create'])->name('products.create');
        Route::post('/products', [AdminController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [AdminController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [AdminController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [AdminController::class, 'destroy'])->name('products.destroy');
    });
});
