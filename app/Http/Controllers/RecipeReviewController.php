<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Stock;
use App\Services\IngredientMatcher;
use App\Services\RecipeStocker;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Step 2 of importing a recipe: the person picks what each ingredient is
 * bought as (or adds it to the catalog, or skips it) before the recipe is saved.
 */
class RecipeReviewController extends Controller
{
    public function show(Request $request, Recipe $draft, IngredientMatcher $matcher)
    {
        $this->authorizeDraft($request, $draft);
        $draft->load(['chef', 'ingredients.product']);
        $atHome = Stock::where('user_id', $request->user()->id)->pluck('quantity', 'product_id');

        return view('recipes.review', [
            'recipe' => $draft,
            'atHome' => $atHome,
            // A few likely items per ingredient, to pick in one tap: what is already in
            // the pantry first, kept apart from the rest of the catalog.
            'suggestions' => $draft->ingredients->mapWithKeys(fn (RecipeIngredient $ingredient) => [$ingredient->id => [
                'pantry' => $matcher->rank($ingredient->name, 3, onlyIds: $atHome->keys()->all()),
                'catalog' => $matcher->rank($ingredient->name, 3 + $atHome->count())->reject(fn (Product $product) => $atHome->has($product->id))->take(3)->values(),
            ]]),
        ]);
    }

    public function finish(Request $request, Recipe $draft, RecipeStocker $stocker)
    {
        $this->authorizeDraft($request, $draft);
        $draft->update(['draft' => false]);

        $message = $request->boolean('stock')
            ? 'Recipe saved. '.RecipeStocker::summary($stocker->stock($draft, $request->user()))
            : 'Recipe saved. Tap Stock ingredients when you plan to cook it.';

        return redirect()->route('recipes.show', $draft)->with('success', $message);
    }

    public function discard(Request $request, Recipe $draft)
    {
        $this->authorizeDraft($request, $draft);
        $chef = $draft->chef;
        $draft->deleteWithPhoto();

        // A chef this import created, with nothing else to show, goes too.
        if ($chef && $chef->created_at >= $draft->created_at->subMinute() && ! Recipe::withoutGlobalScope('published')->where('chef_id', $chef->id)->exists()) {
            $chef->delete();
        }

        return redirect()->route('recipes.import')->with('success', 'Import discarded.');
    }

    public function newItem(Request $request, Recipe $draft, RecipeIngredient $ingredient)
    {
        $this->authorizeDraft($request, $draft, $ingredient);

        return view('recipes.new-item', ['recipe' => $draft, 'ingredient' => $ingredient]);
    }

    /** Adds the ingredient to the catalog now and goes back to the wizard, at that ingredient. */
    public function storeNewItem(Request $request, Recipe $draft, RecipeIngredient $ingredient)
    {
        $this->authorizeDraft($request, $draft, $ingredient);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => ['required', 'string', Rule::in([IngredientMatcher::NEW_PRODUCT_GROUP, ...Product::distinct()->pluck('category')])],
            'size' => 'nullable|string|max:60',
            'price' => 'nullable|numeric|min:0|max:1000000',
        ]);

        $product = Product::create([
            'sku' => 'RCP-'.Str::upper(Str::random(8)),
            'category' => $data['category'],
            'name' => Str::ucfirst(Str::squish($data['name'])),
            'size' => $data['size'] ?? null,
            'price' => $data['price'] ?? 0,
        ]);
        $ingredient->update(['product_id' => $product->id, 'skip' => false]);

        return redirect()->to(route('recipes.review', $draft).'#ingredient-'.$ingredient->id)
            ->with('success', "{$product->name} added to the catalog.");
    }

    private function authorizeDraft(Request $request, Recipe $draft, ?RecipeIngredient $ingredient = null): void
    {
        abort_unless($draft->user_id === $request->user()->id || $request->user()->is_admin, 403);
        abort_if($ingredient && $ingredient->recipe_id !== $draft->id, 404);
    }
}
