<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The catalog ("Discover items") and the signed-in user's own pantry share
 * one page; the pantry is simply the catalog limited to tracked products.
 */
class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $mode = $request->routeIs('pantry') ? 'pantry' : 'catalog';
        $userId = $request->user()->id;

        $query = Product::query()->with([
            'stocks' => fn ($q) => $q->where('user_id', $userId),
            'cartItems' => fn ($q) => $q->where('user_id', $userId),
        ]);

        if ($mode === 'pantry') {
            $query->whereHas('stocks', fn ($q) => $q->where('user_id', $userId));
        }

        if ($request->filled('q')) {
            $term = '%'.addcslashes(mb_substr($request->string('q')->trim()->toString(), 0, 120), '%_\\').'%';
            $query->where(fn ($q) => $q
                ->where('name', 'like', $term)
                ->orWhere('sku', 'like', $term)
                ->orWhere('type', 'like', $term));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($mode === 'pantry') {
            match ($request->input('status')) {
                'low' => $query->whereHas('stocks', fn ($q) => $q->where('user_id', $userId)->whereColumn('quantity', '<', 'target_quantity')),
                'out' => $query->whereHas('stocks', fn ($q) => $q->where('user_id', $userId)->where('quantity', 0)),
                default => null,
            };
        }

        match ($request->input('sort')) {
            'price-asc' => $query->orderBy('price'),
            'price-desc' => $query->orderByDesc('price'),
            default => $query->orderBy('name'),
        };

        $stocks = Stock::where('user_id', $userId);

        return view('inventory', [
            'mode' => $mode,
            'products' => $query->paginate(24)->withQueryString(),
            'stats' => [
                'catalog' => Product::count(),
                'tracked' => (clone $stocks)->count(),
                'low' => (clone $stocks)->whereColumn('quantity', '<', 'target_quantity')->count(),
                'units' => (clone $stocks)->sum('quantity'),
            ],
        ]);
    }

    public function track(Request $request, Product $product)
    {
        Stock::firstOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            ['quantity' => 0, 'target_quantity' => 1],
        );

        return back()->with('success', "{$product->name} is now in My pantry. Set how many you have with the − and + buttons.");
    }

    public function saveStock(Request $request, Product $product)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:0|max:1000000',
            'target_quantity' => 'required|integer|min:1|max:1000000',
            'location' => 'nullable|string|max:120',
        ]);

        Stock::updateOrCreate(['user_id' => $request->user()->id, 'product_id' => $product->id], $data);

        return back()->with('success', 'Home stock saved.');
    }

    /** Nudges the on-hand count by one: "used one" or "found one more". */
    public function adjust(Request $request, Product $product)
    {
        $data = $request->validate(['delta' => 'required|integer|in:-1,1']);

        $stock = Stock::where('user_id', $request->user()->id)->where('product_id', $product->id)->firstOrFail();

        if ($data['delta'] < 0 && $stock->quantity === 0) {
            $message = 'There is no stock to use.';
        } else {
            $stock->increment('quantity', $data['delta']);
            $message = $data['delta'] < 0 ? 'Used one. Stock updated.' : 'Added one to your stock.';
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'quantity' => $stock->quantity,
                'target' => $stock->target_quantity,
            ]);
        }

        return back()->with('success', $message);
    }

    public function untrack(Request $request, Product $product)
    {
        Stock::where('user_id', $request->user()->id)->where('product_id', $product->id)->delete();

        return back()->with('success', 'Removed from My pantry.');
    }

    /** Puts every below-target pantry item on the shopping list for its shortfall. */
    public function restock(Request $request)
    {
        $added = DB::transaction(function () use ($request) {
            $stocks = Stock::where('user_id', $request->user()->id)
                ->whereColumn('quantity', '<', 'target_quantity')
                ->get();

            foreach ($stocks as $stock) {
                $shortfall = $stock->target_quantity - $stock->quantity;
                $item = CartItem::firstOrCreate(
                    ['user_id' => $request->user()->id, 'product_id' => $stock->product_id],
                    ['quantity' => $shortfall],
                );

                // Keep a larger quantity someone already typed in.
                if (! $item->checked) {
                    $item->quantity = max($item->quantity, $shortfall);
                    $item->save();
                }
            }

            return $stocks->count();
        }, 3);

        return redirect()->route('cart')->with('success', $added
            ? "{$added} low ".str('item')->plural($added).' added to your list. Larger quantities you already set were kept.'
            : 'Everything in your pantry is at its target. Nothing to restock.');
    }
}
