<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Stock ingredients": makes sure everything a recipe needs is tracked in the
 * person's pantry and that whatever they have none of is on their list.
 *
 * For each ingredient that isn't skipped:
 *  - not in the catalog  → added to the catalog (group "Recipe Ingredients")
 *  - not in the pantry   → added to the pantry
 *  - none at home        → put on the shopping list
 */
class RecipeStocker
{
    /** @return array{catalog: int, pantry: int, list: int, onList: int, inStock: int} */
    public function stock(Recipe $recipe, User $user): array
    {
        $counts = ['catalog' => 0, 'pantry' => 0, 'list' => 0, 'onList' => 0, 'inStock' => 0];

        DB::transaction(function () use ($recipe, $user, &$counts) {
            foreach ($recipe->ingredients()->with('product')->where('skip', false)->get() as $ingredient) {
                $product = $ingredient->product ?? $this->addToCatalog($ingredient, $counts);

                $stock = Stock::firstOrCreate(
                    ['user_id' => $user->id, 'product_id' => $product->id],
                    ['quantity' => 0, 'target_quantity' => 1],
                );
                $counts['pantry'] += (int) $stock->wasRecentlyCreated;

                if ($stock->quantity > 0) {
                    $counts['inStock']++;

                    continue;
                }

                $item = CartItem::firstOrCreate(
                    ['user_id' => $user->id, 'product_id' => $product->id],
                    ['quantity' => 1],
                );
                $item->wasRecentlyCreated ? $counts['list']++ : $counts['onList']++;
            }
        }, 3);

        return $counts;
    }

    public static function summary(array $counts): string
    {
        $parts = array_filter([
            $counts['list'] ? $counts['list'].' added to your shopping list' : null,
            $counts['pantry'] ? $counts['pantry'].' added to My pantry' : null,
            $counts['catalog'] ? $counts['catalog'].' new to the catalog' : null,
            $counts['onList'] ? $counts['onList'].' already on your list' : null,
            $counts['inStock'] ? $counts['inStock'].' already at home' : null,
        ]);

        return $parts ? Str::ucfirst(implode(', ', $parts)).'.' : 'Nothing to stock: every ingredient is skipped.';
    }

    private function addToCatalog(RecipeIngredient $ingredient, array &$counts): Product
    {
        // Another ingredient in this run may already have created the same item.
        $product = Product::where('category', IngredientMatcher::NEW_PRODUCT_GROUP)
            ->whereRaw('lower(name) = ?', [mb_strtolower($ingredient->name)])
            ->first();

        if (! $product) {
            $product = Product::create([
                'sku' => 'RCP-'.Str::upper(Str::random(8)),
                'category' => IngredientMatcher::NEW_PRODUCT_GROUP,
                'name' => $ingredient->name,
                'price' => 0,
            ]);
            $counts['catalog']++;
        }

        $ingredient->update(['product_id' => $product->id]);

        return $product;
    }
}
