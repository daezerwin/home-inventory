<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RecipeIngredient;
use App\Models\Stock;
use App\Services\IngredientMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

    /**
     * JSON catalog search for the picker, best grocery matches first. What is in
     * the person's pantry comes first (marked "pantry"), so they pick what they
     * already have instead of a look-alike.
     */
    public function search(Request $request, IngredientMatcher $matcher)
    {
        $query = mb_substr($request->string('q')->trim()->toString(), 0, 120);
        $atHome = Stock::where('user_id', $request->user()->id)->pluck('quantity', 'product_id');

        if ($query === '') {
            // Nothing typed: the whole pantry, to pick from without searching.
            $mine = Product::whereKey($atHome->keys())->orderBy('name')->limit(80)->get();
            $catalog = collect();
        } else {
            $mine = $this->find($matcher, $query, 8, $atHome->keys()->all());
            $catalog = $this->find($matcher, $query, 15 + $mine->count())->reject(fn (Product $product) => $atHome->has($product->id))->take(15);
        }

        return response()->json($mine->concat($catalog)->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'size' => $product->size,
            'category' => $product->category,
            'price' => '₱'.number_format($product->price, 2),
            'image' => $product->image,
            'pantry' => $atHome->has($product->id),
            'atHome' => $atHome->get($product->id),
        ])->values());
    }

    /** Ranked matches (loosely within a pantry), else plain name matches; only among $onlyIds when given. */
    private function find(IngredientMatcher $matcher, string $query, int $limit, ?array $onlyIds = null): Collection
    {
        if ($onlyIds === []) {
            return collect();
        }

        $products = $matcher->rank($query, $limit, onlyIds: $onlyIds, loose: true);

        if ($products->isEmpty()) {
            $term = '%'.addcslashes($query, '%_\\').'%';
            $products = Product::where('name', 'like', $term)
                ->when($onlyIds !== null, fn ($products) => $products->whereKey($onlyIds))
                ->orderByRaw('length(name)')->limit($limit)->get();
        }

        return $products;
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
