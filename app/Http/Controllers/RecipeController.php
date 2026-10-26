<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Chef;
use App\Models\Recipe;
use App\Models\Stock;
use App\Services\RecipeImporter;
use App\Services\RecipeImportException;
use App\Services\RecipeStocker;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::with('chef')->withCount('ingredients')->latest('id');

        if ($request->filled('q')) {
            $term = '%'.addcslashes(mb_substr($request->string('q')->trim()->toString(), 0, 120), '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        if ($request->filled('chef')) {
            $query->whereHas('chef', fn ($q) => $q->where('slug', $request->string('chef')->toString()));
        }

        return view('recipes.index', [
            'recipes' => $query->paginate(18)->withQueryString(),
            'chefs' => Chef::has('recipes')->orderBy('name')->get(),
            'heroImage' => Recipe::whereNotNull('image_path')->latest('id')->first()?->image,
        ]);
    }

    public function importForm(Request $request)
    {
        return view('recipes.import', [
            'chefs' => Chef::orderBy('name')->get(),
            'drafts' => Recipe::drafts()->where('user_id', $request->user()->id)->withCount('ingredients')->latest('id')->get(),
        ]);
    }

    public function import(Request $request, RecipeImporter $importer)
    {
        $data = $request->validate([
            'url' => 'required|url:http,https|max:2048',
            'chef_id' => 'nullable|exists:chefs,id',
            'new_chef' => 'nullable|string|max:120',
        ]);

        $url = trim($data['url']);
        $urls = [$url, rtrim($url, '/'), rtrim($url, '/').'/'];

        if ($existing = Recipe::whereIn('source_url', $urls)->first()) {
            return redirect()->route('recipes.show', $existing)->with('success', 'You already have this recipe.');
        }

        if ($draft = Recipe::drafts()->where('user_id', $request->user()->id)->whereIn('source_url', $urls)->first()) {
            return redirect()->route('recipes.review', $draft)->with('success', 'You already started importing this one. Pick up where you left off.');
        }

        try {
            $draft = $importer->import($url, $request->user(), $this->chosenChef($data), draft: true);
        } catch (RecipeImportException $e) {
            return back()->withInput()->withErrors(['url' => $e->getMessage()])->with('manual_url', $url);
        }

        return redirect()->route('recipes.review', $draft);
    }

    public function create(Request $request)
    {
        return view('recipes.form', [
            'recipe' => new Recipe(['source_url' => $request->query('source_url')]),
            'chefs' => Chef::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, RecipeImporter $importer)
    {
        $data = $this->validated($request);

        $recipe = Recipe::create([...$this->attributes($data), 'user_id' => $request->user()->id]);
        $importer->syncIngredients($recipe, $this->ingredientLines($data['ingredients']), $request->user());
        $importer->downloadImage($recipe);

        return redirect()->route('recipes.show', $recipe)->with('success', 'Recipe saved.');
    }

    public function show(Request $request, Recipe $recipe)
    {
        $recipe->load(['chef', 'ingredients.product']);
        $productIds = $recipe->ingredients->pluck('product_id')->filter();
        $userId = $request->user()->id;

        return view('recipes.show', [
            'recipe' => $recipe,
            'stocks' => Stock::where('user_id', $userId)->whereIn('product_id', $productIds)->get()->keyBy('product_id'),
            'cart' => CartItem::where('user_id', $userId)->whereIn('product_id', $productIds)->get()->keyBy('product_id'),
            'canDelete' => $this->canDelete($request, $recipe),
        ]);
    }

    public function edit(Recipe $recipe)
    {
        return view('recipes.form', ['recipe' => $recipe->load('ingredients'), 'chefs' => Chef::orderBy('name')->get()]);
    }

    public function update(Request $request, Recipe $recipe, RecipeImporter $importer)
    {
        $data = $this->validated($request);
        $attributes = $this->attributes($data);
        $newPhoto = $attributes['image_url'] !== $recipe->image_url;

        if ($attributes['title'] !== $recipe->title) {
            $recipe->slug = Recipe::uniqueSlug($attributes['title'], $recipe->id);
        }

        if ($newPhoto) {
            $attributes['image_path'] = null;
        }

        $recipe->update($attributes);
        $importer->syncIngredients($recipe, $this->ingredientLines($data['ingredients']), $request->user());

        if ($newPhoto) {
            $importer->downloadImage($recipe);
        }

        return redirect()->route('recipes.show', $recipe)->with('success', 'Recipe saved.');
    }

    public function destroy(Request $request, Recipe $recipe)
    {
        abort_unless($this->canDelete($request, $recipe), 403);

        $recipe->deleteWithPhoto();

        return redirect()->route('recipes.index')->with('success', "{$recipe->title} deleted.");
    }

    public function stock(Request $request, Recipe $recipe, RecipeStocker $stocker)
    {
        $counts = $stocker->stock($recipe, $request->user());

        return back()->with('success', RecipeStocker::summary($counts));
    }

    /**
     * "Use ingredients": takes what went into the recipe out of the person's
     * pantry, never below zero. What runs out can go on the shopping list.
     */
    public function useIngredients(Request $request, Recipe $recipe)
    {
        $data = $request->validate([
            'items' => 'nullable|array',
            'items.*.use' => 'nullable|boolean',
            'items.*.quantity' => 'nullable|integer|min:1|max:1000',
            'list_empty' => 'nullable|boolean',
        ]);
        $userId = $request->user()->id;

        // Only what was ticked, of this recipe's ingredients, that is in this person's pantry.
        $taking = collect($data['items'] ?? [])
            ->filter(fn (array $item) => ! empty($item['use']))
            ->map(fn (array $item) => (int) ($item['quantity'] ?? 1));
        $stocks = Stock::with('product')
            ->where('user_id', $userId)
            ->where('quantity', '>', 0)
            ->whereIn('product_id', $taking->keys()->intersect($recipe->ingredients()->whereNotNull('product_id')->pluck('product_id')))
            ->get();

        if ($stocks->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Tick at least one ingredient you used.']);
        }

        $used = [];
        $listed = 0;
        $ranOut = 0;

        DB::transaction(function () use ($stocks, $taking, $userId, $request, &$used, &$listed, &$ranOut) {
            foreach ($stocks as $stock) {
                $take = min($stock->quantity, $taking[$stock->product_id]);
                $stock->decrement('quantity', $take);
                $used[] = "{$take} {$stock->product->name}";

                if ($stock->quantity > 0) {
                    continue;
                }

                $ranOut++;

                if ($request->boolean('list_empty')) {
                    $item = CartItem::firstOrCreate(
                        ['user_id' => $userId, 'product_id' => $stock->product_id],
                        ['quantity' => max(1, $stock->target_quantity)],
                    );
                    $listed += (int) $item->wasRecentlyCreated;
                }
            }
        });

        $message = 'Took '.Arr::join($used, ', ', ' and ').' out of your pantry.';
        if ($ranOut) {
            $message .= $ranOut === 1 ? ' One ran out' : " {$ranOut} ran out";
            $message .= $listed ? ($listed === $ranOut ? ' and went on your shopping list.' : "; {$listed} went on your shopping list.") : '.';
        }

        return back()->with('success', $message);
    }

    private function canDelete(Request $request, Recipe $recipe): bool
    {
        return $request->user()->is_admin || $recipe->user_id === $request->user()->id;
    }

    private function chosenChef(array $data): ?Chef
    {
        if (filled($data['new_chef'] ?? null)) {
            return Chef::findOrCreateByName($data['new_chef']);
        }

        return isset($data['chef_id']) ? Chef::find($data['chef_id']) : null;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:250',
            'chef_id' => 'nullable|exists:chefs,id',
            'new_chef' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:5000',
            'source_url' => 'nullable|url:http,https|max:2048',
            'image_url' => 'nullable|url:http,https|max:2048',
            'servings' => 'nullable|string|max:120',
            'prep_minutes' => 'nullable|integer|min:0|max:65000',
            'cook_minutes' => 'nullable|integer|min:0|max:65000',
            'total_minutes' => 'nullable|integer|min:0|max:65000',
            'ingredients' => 'required|string|max:20000',
            'instructions' => 'nullable|string|max:50000',
        ]);
    }

    private function attributes(array $data): array
    {
        $fields = ['title', 'description', 'source_url', 'image_url', 'servings', 'prep_minutes', 'cook_minutes', 'total_minutes'];

        return [
            // Fields left out of the form are cleared rather than kept.
            ...array_combine($fields, array_map(fn ($field) => $data[$field] ?? null, $fields)),
            'chef_id' => $this->chosenChef($data)?->id,
            'instructions' => $this->instructionSteps($data['instructions'] ?? ''),
        ];
    }

    /** @return list<array{line: string}> */
    private function ingredientLines(string $text): array
    {
        return collect(preg_split('/\R/', $text))
            ->map(fn ($line) => Str::limit(Str::squish(preg_replace('/^\s*[-*•]\s*/u', '', $line)), 480, ''))
            ->filter()
            ->map(fn ($line) => ['line' => $line])
            ->values()
            ->all();
    }

    /** One step per line; "# Heading" lines start a section. */
    private function instructionSteps(string $text): array
    {
        return collect(preg_split('/\R/', $text))
            ->map(fn ($line) => Str::squish($line))
            ->filter()
            ->map(fn ($line) => str_starts_with($line, '#')
                ? ['type' => 'section', 'text' => ltrim($line, '# ')]
                : ['type' => 'step', 'text' => preg_replace('/^\d+[.)]\s*/', '', $line)])
            ->values()
            ->all();
    }
}
