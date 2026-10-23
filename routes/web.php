<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ChefController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeIngredientController;
use App\Http\Controllers\RecipeReviewController;
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

    // Recipes and where they come from
    Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
    Route::get('/recipes/import', [RecipeController::class, 'importForm'])->name('recipes.import');
    Route::post('/recipes/import', [RecipeController::class, 'import'])->middleware('throttle:20,1');
    Route::get('/recipes/import/{draft}', [RecipeReviewController::class, 'show'])->name('recipes.review');
    Route::post('/recipes/import/{draft}', [RecipeReviewController::class, 'finish'])->name('recipes.finish');
    Route::delete('/recipes/import/{draft}', [RecipeReviewController::class, 'discard'])->name('recipes.discard');
    Route::get('/recipes/import/{draft}/ingredients/{ingredient}/new-item', [RecipeReviewController::class, 'newItem'])->name('recipes.new-item');
    Route::post('/recipes/import/{draft}/ingredients/{ingredient}/new-item', [RecipeReviewController::class, 'storeNewItem']);
    Route::get('/recipes/create', [RecipeController::class, 'create'])->name('recipes.create');
    Route::post('/recipes', [RecipeController::class, 'store'])->name('recipes.store');
    Route::get('/recipes/{recipe:slug}', [RecipeController::class, 'show'])->name('recipes.show');
    Route::get('/recipes/{recipe:slug}/edit', [RecipeController::class, 'edit'])->name('recipes.edit');
    Route::put('/recipes/{recipe:slug}', [RecipeController::class, 'update'])->name('recipes.update');
    Route::delete('/recipes/{recipe:slug}', [RecipeController::class, 'destroy'])->name('recipes.destroy');
    Route::post('/recipes/{recipe:slug}/stock', [RecipeController::class, 'stock'])->name('recipes.stock');
    Route::patch('/recipe-ingredients/{ingredient}', [RecipeIngredientController::class, 'update'])->name('recipe-ingredients.update');
    Route::get('/products/search', [RecipeIngredientController::class, 'search'])->name('products.search');

    Route::get('/chefs', [ChefController::class, 'index'])->name('chefs.index');
    Route::get('/chefs/{chef:slug}', [ChefController::class, 'show'])->name('chefs.show');
    Route::get('/chefs/{chef:slug}/edit', [ChefController::class, 'edit'])->name('chefs.edit');
    Route::put('/chefs/{chef:slug}', [ChefController::class, 'update'])->name('chefs.update');
    Route::delete('/chefs/{chef:slug}', [ChefController::class, 'destroy'])->name('chefs.destroy');

    // Shared catalog management
    Route::prefix('admin')->middleware(Admin::class)->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/status', [AdminController::class, 'status'])->name('status');
        Route::post('/import', [AdminController::class, 'import'])->name('import');
        Route::post('/images/retry', [AdminController::class, 'retryImages'])->name('images.retry');
        Route::post('/images/run', [AdminController::class, 'runImages'])->name('images.run');
        Route::post('/reset/pantries', [AdminController::class, 'resetPantries'])->name('reset.pantries');
        Route::post('/reset/catalog', [AdminController::class, 'resetCatalog'])->name('reset.catalog');
        Route::get('/products/create', [AdminController::class, 'create'])->name('products.create');
        Route::post('/products', [AdminController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [AdminController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [AdminController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [AdminController::class, 'destroy'])->name('products.destroy');
    });
});
