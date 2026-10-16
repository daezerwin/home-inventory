<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');

        // Some pages list the groups too (chips, datalist), so the results are
        // kept on the request to avoid querying twice.
        View::composer(['layouts.app', 'inventory', 'admin.form'], function ($view) {
            $shared = request()->attributes;

            if (! $shared->has('categories')) {
                $shared->set('categories', Product::selectRaw('category, COUNT(*) as total')
                    ->groupBy('category')
                    ->orderBy('category')
                    ->get());
                $shared->set('cartCount', auth()->check() ? CartItem::openCountFor(auth()->id()) : 0);
            }

            $view->with('categories', $shared->get('categories'));
            $view->with('cartCount', $shared->get('cartCount'));
        });
    }
}
