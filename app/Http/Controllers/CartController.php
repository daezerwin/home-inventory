<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $items = CartItem::with('product')
            ->where('user_id', $request->user()->id)
            ->get()
            ->sortBy(fn (CartItem $item) => [$item->product->category, $item->product->name]);

        // Grouped by catalog group so the list follows the aisles in the store.
        return view('cart', [
            'items' => $items,
            'groups' => $items->groupBy(fn (CartItem $item) => $item->product->category),
        ]);
    }

    public function add(Request $request, Product $product)
    {
        $data = $request->validate(['quantity' => 'sometimes|integer|min:1|max:1000000']);

        $item = DB::transaction(function () use ($request, $product, $data) {
            $item = CartItem::firstOrCreate(
                ['user_id' => $request->user()->id, 'product_id' => $product->id],
                ['quantity' => 0],
            );
            $item->quantity = min(1000000, $item->quantity + ($data['quantity'] ?? 1));
            $item->checked = false;
            $item->save();

            return $item;
        }, 3);

        return $this->respond($request, 'Added to your shopping list.', ['quantity' => $item->quantity]);
    }

    public function update(Request $request, CartItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'quantity' => 'sometimes|required|integer|min:1|max:1000000',
            'checked' => 'sometimes|required|boolean',
        ]);
        $item->update($data);

        return $this->respond($request, 'Shopping list updated.', [
            'quantity' => $item->quantity,
            'checked' => $item->checked,
        ]);
    }

    public function destroy(Request $request, CartItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);
        $item->delete();

        return $this->respond($request, 'Item removed from the list.');
    }

    public function checkout(Request $request)
    {
        $count = DB::transaction(function () use ($request) {
            $items = CartItem::where('user_id', $request->user()->id)
                ->where('checked', true)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $stock = Stock::firstOrCreate(
                    ['user_id' => $request->user()->id, 'product_id' => $item->product_id],
                    ['quantity' => 0, 'target_quantity' => 1],
                );
                $stock->increment('quantity', $item->quantity);
                $item->delete();
            }

            return $items->count();
        }, 3);

        return back()->with('success', $count
            ? "{$count} purchased ".str('product')->plural($count).' moved into your home stock.'
            : 'Check the items you bought first.');
    }

    private function respond(Request $request, string $message, array $data = [])
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'cartCount' => CartItem::openCountFor($request->user()->id),
                ...$data,
            ]);
        }

        return back()->with('success', $message);
    }
}
