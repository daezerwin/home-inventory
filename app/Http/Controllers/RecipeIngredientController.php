<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RecipeIngredient;
use App\Services\IngredientMatcher;
use Illuminate\Http\Request;

class RecipeIngredientController extends Controller
{
    /** Changes which catalog product an ingredient is bought as, or skips it. */
    public function update(Request $request, RecipeIngredient $ingredient)
    {
        $data = $request->validate([
            'action' => 'required|in:product,new,skip,unskip',
            'product_id' => 'required_if:action,product|nullable|exists:products,id',
            'name' => 'nullable|string|max:120',
        ]);

        $message = match ($data['action']) {
            'product' => $this->link($ingredient, Product::findOrFail($data['product_id'])),
            'new' => $this->markNew($ingredient, $data['name'] ?? null),
            'skip' => tap("{$ingredient->name} will be skipped.", fn () => $ingredient->update(['skip' => true])),
            'unskip' => tap("{$ingredient->name} is back on the recipe's shopping.", fn () => $ingredient->update(['skip' => false])),
        };

        return back()->with('success', $message);
    }

    /** JSON catalog search for the "Change" picker, best grocery matches first. */
    public function search(Request $request, IngredientMatcher $matcher)
    {
        $query = mb_substr($request->string('q')->trim()->toString(), 0, 120);

        if ($query === '') {
            return response()->json([]);
        }

        $products = $matcher->rank($query, 15);

        if ($products->isEmpty()) {
            $term = '%'.addcslashes($query, '%_\\').'%';
            $products = Product::where('name', 'like', $term)->orderByRaw('length(name)')->limit(15)->get();
        }

        return response()->json($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'size' => $product->size,
            'category' => $product->category,
            'price' => '₱'.number_format($product->price, 2),
            'image' => $product->image,
        ]));
    }

    private function link(RecipeIngredient $ingredient, Product $product): string
    {
        $ingredient->update(['product_id' => $product->id, 'skip' => false]);

        return "{$ingredient->name} will be bought as {$product->name}.";
    }

    private function markNew(RecipeIngredient $ingredient, ?string $name): string
    {
        $name = filled($name) ? ucfirst(trim($name)) : $ingredient->name;
        $ingredient->update(['product_id' => null, 'skip' => false, 'name' => $name]);

        return "{$name} will be added to the catalog when you stock this recipe.";
    }
}
