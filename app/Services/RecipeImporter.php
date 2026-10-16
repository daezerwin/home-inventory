<?php

namespace App\Services;

use App\Models\Chef;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Saves scraped or hand-written recipes and links their ingredients to the catalog. */
class RecipeImporter
{
    public function __construct(
        private RecipeScraper $scraper,
        private IngredientMatcher $matcher,
        private ImageDownloader $images,
    ) {}

    /**
     * Imports the recipe at $url. The chef is the one given, or else the
     * page's author (falling back to the site name), created if new.
     */
    public function import(string $url, User $user, ?Chef $chef = null): Recipe
    {
        $data = $this->scraper->fetch($url);

        $chef ??= Chef::findOrCreateByName($data['author'] ?? $data['site_name'], [
            'site_name' => $data['site_name'],
            'website' => $data['website'],
        ]);

        $recipe = DB::transaction(function () use ($data, $user, $chef) {
            $recipe = Recipe::create([
                ...collect($data)->only([
                    'title', 'description', 'source_url', 'image_url', 'servings',
                    'prep_minutes', 'cook_minutes', 'total_minutes', 'instructions',
                ])->all(),
                'chef_id' => $chef?->id,
                'user_id' => $user->id,
            ]);

            $this->syncIngredients($recipe, $data['ingredients']);

            return $recipe;
        });

        $this->downloadImage($recipe);

        return $recipe;
    }

    /**
     * Replaces a recipe's ingredient list, keeping the catalog match and skip
     * choice of every line that didn't change.
     *
     * @param  list<array{line: string, name?: ?string}>  $ingredients
     */
    public function syncIngredients(Recipe $recipe, array $ingredients): void
    {
        $existing = $recipe->ingredients()->get()->groupBy('line');
        $keep = [];

        foreach (array_values($ingredients) as $position => $ingredient) {
            $line = trim($ingredient['line']);
            $current = $existing->get($line)?->shift();

            if ($current) {
                $current->update(['position' => $position]);
                $keep[] = $current->id;

                continue;
            }

            $name = $this->matcher->name($line, $ingredient['name'] ?? null);
            $staple = $this->matcher->isStaple($name);

            $keep[] = $recipe->ingredients()->create([
                'position' => $position,
                'line' => $line,
                'name' => $name,
                'skip' => $staple,
                'product_id' => $staple ? null : $this->matcher->match($name)?->id,
            ])->id;
        }

        $recipe->ingredients()->whereNotIn('id', $keep)->delete();
    }

    /** Keeps a local copy of the recipe photo. A failed download just leaves the placeholder. */
    public function downloadImage(Recipe $recipe): void
    {
        if (! $recipe->image_url) {
            return;
        }

        try {
            $path = $this->images->store($recipe->image_url, 'recipes', config('inventory.recipe_image_max_size'));
            $recipe->update(['image_path' => $path]);
        } catch (RuntimeException $e) {
            Log::warning("Recipe photo download failed for recipe {$recipe->id}: {$e->getMessage()}");
        }
    }
}
