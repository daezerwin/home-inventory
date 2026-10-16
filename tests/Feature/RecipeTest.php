<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Chef;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\User;
use App\Services\IngredientMatcher;
use App\Services\RecipeScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;

    // An IP literal skips DNS, so these tests run offline; Http::fake serves the pages.
    private const PAGE = 'https://93.184.216.34/garlic-eggplant-adobo/';

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/recipe-page.html'));
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function catalog(): void
    {
        foreach ([
            ['SS Eggplant', 'Fresh Produce', 'Vegetables', 49.20],
            ['Amoy Spicy Eggplant Sauce', 'Pantry', 'Condiments & Sauces', 71.50],
            ['Global Garlic', 'Fresh Produce', 'Vegetables', 99.60],
            ['Hiho Garlic', 'Snacks', 'Nuts & Seeds', 17.50],
            ['Select Soy Sauce', 'Pantry', 'Condiments & Sauces', 46.00],
            ['J&Y Laurel Leaves', 'Pantry', 'Condiments & Sauces', 9.25],
            ['Juan Fresh Eggs', 'Chilled & Dairy Items', 'Eggs', 145],
        ] as $i => [$name, $category, $type, $price]) {
            Product::create(['sku' => "P{$i}", 'name' => $name, 'category' => $category, 'type' => $type, 'price' => $price]);
        }
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    public function test_scraper_reads_json_ld_graphs_author_references_and_sections(): void
    {
        $data = app(RecipeScraper::class)->parse($this->fixture(), self::PAGE);

        $this->assertSame('Garlic Eggplant Adobo & Rice', $data['title']);
        $this->assertSame('Silky eggplant in a garlicky soy and vinegar sauce.', $data['description']);
        $this->assertSame('Maria Santos', $data['author']);
        $this->assertSame("Lola's Table", $data['site_name']);
        $this->assertSame('https://93.184.216.34/images/adobo.png', $data['image_url']);
        $this->assertSame('4 people', $data['servings']);
        $this->assertSame([10, 65, 75], [$data['prep_minutes'], $data['cook_minutes'], $data['total_minutes']]);
        $this->assertSame('Chinese eggplant', $data['ingredients'][0]['name']);
        $this->assertSame('5 cloves garlic, chopped', $data['ingredients'][1]['line']);
        $this->assertSame([
            ['type' => 'section', 'text' => 'Prepare'],
            ['type' => 'step', 'text' => 'Slice the eggplant.'],
            ['type' => 'section', 'text' => 'Cook'],
            ['type' => 'step', 'text' => 'Fry the eggplant until golden.'],
            ['type' => 'step', 'text' => 'Simmer with garlic, soy sauce and bay leaves.'],
        ], $data['instructions']);
    }

    public function test_ingredient_lines_become_names_and_match_groceries(): void
    {
        $this->catalog();
        $matcher = app(IngredientMatcher::class);

        $this->assertSame('Garlic', $matcher->name('3 cloves garlic crushed and chopped'));
        $this->assertSame('Plain flour', $matcher->name('100g plain flour'));
        $this->assertSame('Vegetable oil', $matcher->name('1 tbsp sunflower or vegetable oil plus extra for frying'));
        $this->assertSame('Salt and pepper', $matcher->name('salt and pepper to taste'));
        $this->assertSame('Eggs', $matcher->name('2 large eggs'));
        $this->assertTrue($matcher->isStaple('Water'));

        $this->assertSame('SS Eggplant', $matcher->match('Chinese eggplant')?->name);
        $this->assertSame('Global Garlic', $matcher->match('garlic')?->name);
        $this->assertSame('J&Y Laurel Leaves', $matcher->match('dried bay leaves')?->name);
        $this->assertSame('Juan Fresh Eggs', $matcher->match('eggs')?->name);
        $this->assertNull($matcher->match('fresh turmeric root'));
    }

    public function test_import_saves_recipe_chef_photo_and_matched_ingredients(): void
    {
        Storage::fake('public');
        $this->catalog();
        Http::fake([
            '93.184.216.34/images/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/*' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/recipes/import', ['url' => self::PAGE]);

        $recipe = Recipe::with('ingredients.product', 'chef')->firstOrFail();
        $response->assertRedirect(route('recipes.show', $recipe));
        $this->assertSame('garlic-eggplant-adobo-rice', $recipe->slug);
        $this->assertSame('Maria Santos', $recipe->chef->name);
        $this->assertSame("Lola's Table", $recipe->chef->site_name);
        $this->assertSame('https://93.184.216.34', $recipe->chef->website);
        $this->assertSame($user->id, $recipe->user_id);
        $this->assertStringEndsWith('.webp', $recipe->image_path);
        Storage::disk('public')->assertExists($recipe->image_path);
        $this->assertSame([1600, 800], array_slice(getimagesizefromstring(Storage::disk('public')->get($recipe->image_path)), 0, 2));

        $matches = $recipe->ingredients->mapWithKeys(fn ($i) => [$i->name => $i->skip ? 'skip' : $i->product?->name])->all();
        $this->assertSame([
            'Chinese eggplant' => 'SS Eggplant',
            'Garlic' => 'Global Garlic',
            'Soy sauce' => 'Select Soy Sauce',
            'Dried bay leaves' => 'J&Y Laurel Leaves',
            'Water' => 'skip',
            'Fresh turmeric root' => null,
        ], $matches);

        // Importing the same link again opens the saved copy.
        $this->post('/recipes/import', ['url' => self::PAGE])->assertRedirect(route('recipes.show', $recipe));
        $this->assertDatabaseCount('recipes', 1);
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Maria Santos')->assertSee('SS Eggplant')->assertSee('Fry the eggplant until golden.');
    }

    public function test_import_explains_blocked_and_unreadable_pages(): void
    {
        $user = User::factory()->create();
        Http::fake([
            '93.184.216.34/blocked*' => Http::response('Forbidden', 403),
            '93.184.216.34/*' => Http::response('<html><title>Just a blog post</title></html>', 200),
        ]);

        $this->actingAs($user)->from('/recipes/import')->post('/recipes/import', ['url' => 'https://93.184.216.34/blocked'])
            ->assertRedirect('/recipes/import')
            ->assertSessionHasErrors(['url' => '93.184.216.34 refused the download (HTTP 403). Some sites block apps like this one.'])
            ->assertSessionHas('manual_url', 'https://93.184.216.34/blocked');

        $this->post('/recipes/import', ['url' => 'https://93.184.216.34/post'])->assertSessionHasErrors('url');
        $this->post('/recipes/import', ['url' => 'http://127.0.0.1/recipe'])->assertSessionHasErrors('url');
        $this->post('/recipes/import', ['url' => 'http://10.1.2.3/recipe'])->assertSessionHasErrors('url');
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_stocking_ingredients_fills_catalog_pantry_and_list_once(): void
    {
        $this->catalog();
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$eggplant, $garlic, $soy] = [Product::where('name', 'SS Eggplant')->first(), Product::where('name', 'Global Garlic')->first(), Product::where('name', 'Select Soy Sauce')->first()];
        Stock::create(['user_id' => $user->id, 'product_id' => $eggplant->id, 'quantity' => 2, 'target_quantity' => 2]);
        Stock::create(['user_id' => $user->id, 'product_id' => $garlic->id, 'quantity' => 0, 'target_quantity' => 1]);

        $recipe = Recipe::create(['title' => 'Adobo']);
        foreach ([
            ['3 eggplants', 'Chinese eggplant', $eggplant->id, false],  // at home: nothing to do
            ['5 cloves garlic', 'Garlic', $garlic->id, false],          // in pantry, none left → list
            ['3 tbsp soy sauce', 'Soy sauce', $soy->id, false],         // catalog only → pantry + list
            ['1 thumb turmeric', 'Turmeric', null, false],              // unknown → catalog + pantry + list
            ['1 thumb turmeric root', 'turmeric', null, false],         // same new item, created once
            ['1/4 cup water', 'Water', null, true],                     // skipped
        ] as $position => [$line, $name, $productId, $skip]) {
            $recipe->ingredients()->create(compact('position', 'line', 'name', 'skip') + ['product_id' => $productId]);
        }

        $this->actingAs($user)->post(route('recipes.stock', $recipe))->assertRedirect()
            ->assertSessionHas('success', '3 added to your shopping list, 2 added to My pantry, 1 new to the catalog, 1 already on your list, 1 already at home.');

        $turmeric = Product::where('name', 'Turmeric')->firstOrFail();
        $this->assertSame(IngredientMatcher::NEW_PRODUCT_GROUP, $turmeric->category);
        $this->assertSame(2, $recipe->ingredients()->where('product_id', $turmeric->id)->count());
        $this->assertEqualsCanonicalizing([$garlic->id, $soy->id, $turmeric->id], CartItem::where('user_id', $user->id)->pluck('product_id')->all());
        $this->assertSame(4, Stock::where('user_id', $user->id)->count());
        $this->assertSame(0, Stock::where('user_id', $other->id)->count());

        // Again: nothing new, nothing duplicated.
        $this->post(route('recipes.stock', $recipe))->assertSessionHas('success', '4 already on your list, 1 already at home.');
        $this->assertSame(3, CartItem::where('user_id', $user->id)->count());
        $this->assertSame(1, Product::where('name', 'Turmeric')->count());
    }

    public function test_ingredient_matches_can_be_changed_and_searched(): void
    {
        $this->catalog();
        $user = User::factory()->create();
        $recipe = Recipe::create(['title' => 'Adobo']);
        $ingredient = $recipe->ingredients()->create(['line' => '5 cloves garlic', 'name' => 'Garlic']);
        $snack = Product::where('name', 'Hiho Garlic')->first();

        $this->actingAs($user)->getJson('/products/search?q=garlic')->assertOk()->assertJsonPath('0.name', 'Global Garlic');
        $this->getJson('/products/search?q=hiho')->assertJsonPath('0.name', 'Hiho Garlic');

        $this->patch(route('recipe-ingredients.update', $ingredient), ['action' => 'product', 'product_id' => $snack->id])->assertRedirect();
        $this->assertSame($snack->id, $ingredient->fresh()->product_id);
        $this->patch(route('recipe-ingredients.update', $ingredient), ['action' => 'new', 'name' => 'native garlic']);
        $this->assertSame(['product_id' => null, 'name' => 'Native garlic'], $ingredient->fresh()->only('product_id', 'name'));
        $this->patch(route('recipe-ingredients.update', $ingredient), ['action' => 'skip']);
        $this->assertTrue($ingredient->fresh()->skip);
        $this->patch(route('recipe-ingredients.update', $ingredient), ['action' => 'unskip']);
        $this->assertFalse($ingredient->fresh()->skip);
        $this->patch(route('recipe-ingredients.update', $ingredient), ['action' => 'product'])->assertSessionHasErrors('product_id');
    }

    public function test_recipes_can_be_written_and_edited_by_hand_keeping_matches(): void
    {
        $this->catalog();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/recipes', [
            'title' => 'Garlic rice',
            'new_chef' => "Lola's kitchen",
            'ingredients' => "- 5 cloves garlic, minced\n\n2 cups rice",
            'instructions' => "# Prep\n1. Mince the garlic.\n2) Fry it.",
            'total_minutes' => 20,
        ])->assertRedirect();

        $recipe = Recipe::with('ingredients', 'chef')->firstOrFail();
        $this->assertSame("Lola's kitchen", $recipe->chef->name);
        $this->assertSame(['5 cloves garlic, minced', '2 cups rice'], $recipe->ingredients->pluck('line')->all());
        $this->assertSame([['type' => 'section', 'text' => 'Prep'], ['type' => 'step', 'text' => 'Mince the garlic.'], ['type' => 'step', 'text' => 'Fry it.']], $recipe->instructions);

        // A hand-picked match survives an edit that keeps the line.
        $garlicLine = $recipe->ingredients->first();
        $snack = Product::where('name', 'Hiho Garlic')->first();
        $garlicLine->update(['product_id' => $snack->id]);

        $this->put(route('recipes.update', $recipe), [
            'title' => 'Garlic fried rice',
            'chef_id' => $recipe->chef_id,
            'ingredients' => "2 eggs\n5 cloves garlic, minced",
        ])->assertRedirect();

        $recipe->refresh()->load('ingredients');
        $this->assertSame('garlic-fried-rice', $recipe->slug);
        $this->assertSame(['2 eggs', '5 cloves garlic, minced'], $recipe->ingredients->pluck('line')->all());
        $this->assertSame($snack->id, $recipe->ingredients[1]->product_id);
        $this->assertSame('Juan Fresh Eggs', $recipe->ingredients[0]->product->name);
    }

    public function test_recipe_and_chef_pages_render(): void
    {
        $user = User::factory()->create();
        $chef = Chef::create(['name' => 'Vanjo Merano', 'site_name' => 'Panlasang Pinoy', 'website' => 'https://panlasangpinoy.com']);
        $recipe = Recipe::create(['title' => 'Adobong Talong', 'chef_id' => $chef->id, 'image_path' => 'recipes/a.webp', 'total_minutes' => 30]);
        $recipe->ingredients()->create(['line' => '1 eggplant', 'name' => 'Eggplant']);
        Chef::create(['name' => 'Empty Kitchen']);

        $this->actingAs($user);
        foreach (['/recipes', '/recipes?chef=vanjo-merano', '/recipes?q=adobo', '/recipes/import', '/recipes/create', route('recipes.show', $recipe), route('recipes.edit', $recipe), '/chefs', route('chefs.show', $chef), route('chefs.edit', $chef), '/chefs/empty-kitchen'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/chefs')->assertSee('Vanjo Merano')->assertSee('panlasangpinoy.com')->assertSee('data-parallax', false);
        $this->get(route('chefs.show', $chef))->assertSee('Adobong Talong')->assertSee('storage/recipes/a.webp', false);
        $this->get('/recipes?chef=empty-kitchen')->assertDontSee('Adobong Talong');

        $this->put(route('chefs.update', $chef), ['name' => 'Chef Vanjo', 'hero_recipe_id' => $recipe->id])->assertRedirect('/chefs/chef-vanjo');
        $this->assertSame($recipe->id, $chef->fresh()->hero_recipe_id);
        $this->put(route('chefs.update', $chef->fresh()), ['name' => 'Chef Vanjo', 'hero_recipe_id' => 999])->assertSessionHasErrors('hero_recipe_id');
    }

    public function test_only_the_importer_or_an_admin_can_delete_recipes_and_admins_remove_chefs(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $chef = Chef::create(['name' => 'Vanjo Merano']);
        $recipe = Recipe::create(['title' => 'Adobo', 'user_id' => $owner->id, 'chef_id' => $chef->id]);

        $this->actingAs($other)->delete(route('recipes.destroy', $recipe))->assertForbidden();
        $this->delete(route('chefs.destroy', $chef))->assertForbidden();
        $this->actingAs($this->admin())->delete(route('chefs.destroy', $chef))->assertRedirect('/chefs');
        $this->assertNull($recipe->fresh()->chef_id);

        $this->actingAs($owner)->delete(route('recipes.destroy', $recipe))->assertRedirect('/recipes');
        $this->assertDatabaseCount('recipes', 0);
    }
}
