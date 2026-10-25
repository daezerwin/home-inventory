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
use Illuminate\Http\UploadedFile;
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

    public function test_import_is_a_wizard_where_ingredients_are_picked_before_saving(): void
    {
        Storage::fake('public');
        $this->catalog();
        Http::fake([
            '93.184.216.34/images/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/*' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();

        // Step 1: the link is fetched into a draft, and step 2 opens.
        $response = $this->actingAs($user)->post('/recipes/import', ['url' => self::PAGE]);

        $draft = Recipe::drafts()->with('ingredients.product', 'chef')->firstOrFail();
        $response->assertRedirect(route('recipes.review', $draft));
        $this->assertTrue($draft->draft);
        $this->assertSame('garlic-eggplant-adobo-rice', $draft->slug);
        $this->assertSame('Maria Santos', $draft->chef->name);
        $this->assertSame("Lola's Table", $draft->chef->site_name);
        $this->assertSame('https://93.184.216.34', $draft->chef->website);
        $this->assertSame($user->id, $draft->user_id);
        $this->assertStringEndsWith('.webp', $draft->image_path);
        Storage::disk('public')->assertExists($draft->image_path);
        $this->assertSame([1600, 800], array_slice(getimagesizefromstring(Storage::disk('public')->get($draft->image_path)), 0, 2));

        // Suggested matches are preselected; the person can change any of them.
        $matches = $draft->ingredients->mapWithKeys(fn ($i) => [$i->name => $i->skip ? 'skip' : $i->product?->name])->all();
        $this->assertSame([
            'Chinese eggplant' => 'SS Eggplant',
            'Garlic' => 'Global Garlic',
            'Soy sauce' => 'Select Soy Sauce',
            'Dried bay leaves' => 'J&Y Laurel Leaves',
            'Water' => 'skip',
            'Fresh turmeric root' => null,
        ], $matches);

        // A draft is nowhere else yet, and importing the link again resumes it.
        $this->get('/recipes')->assertDontSee('Garlic Eggplant Adobo');
        $this->get('/recipes/'.$draft->slug)->assertNotFound();
        $this->get('/recipes/import')->assertSee('Pick up where you left off')->assertSee(route('recipes.review', $draft), false);
        $this->post('/recipes/import', ['url' => self::PAGE])->assertRedirect(route('recipes.review', $draft));
        $this->assertSame(1, Recipe::drafts()->count());

        $this->get(route('recipes.review', $draft))->assertOk()
            ->assertSee('Pick the ingredients.')
            ->assertSee('5 cloves garlic, chopped')
            ->assertSee('Global Garlic')
            ->assertSee('Not picked yet');

        // Turmeric isn't in the catalog: add it as a new item, then land back in the wizard on that ingredient.
        $turmeric = $draft->ingredients->firstWhere('name', 'Fresh turmeric root');
        $this->get(route('recipes.new-item', [$draft, $turmeric]))->assertOk()->assertSee('Add Fresh turmeric root.');
        $this->post(route('recipes.new-item', [$draft, $turmeric]), ['name' => 'Turmeric root', 'category' => 'Fresh Produce', 'size' => '100g', 'price' => '35'])
            ->assertRedirect(route('recipes.review', $draft).'#ingredient-'.$turmeric->id);
        $product = Product::where('name', 'Turmeric root')->firstOrFail();
        $this->assertSame(['Fresh Produce', '100g', '35.00'], [$product->category, $product->size, (string) $product->price]);
        $this->assertSame($product->id, $turmeric->fresh()->product_id);
        $this->post(route('recipes.new-item', [$draft, $turmeric]), ['name' => 'Turmeric', 'category' => 'Made up group'])->assertSessionHasErrors('category');

        // Picking another suggestion.
        $garlic = $draft->ingredients->firstWhere('name', 'Garlic');
        $peeled = Product::create(['sku' => 'G2', 'category' => 'Fresh Produce', 'name' => 'Peeled Garlic', 'price' => 39]);
        $this->from(route('recipes.review', $draft))
            ->patch(route('recipe-ingredients.update', $garlic), ['action' => 'product', 'product_id' => $peeled->id])
            ->assertRedirect(route('recipes.review', $draft));
        $this->assertSame($peeled->id, $garlic->fresh()->product_id);

        // Only the person importing (or an admin) can work on the draft.
        $this->actingAs(User::factory()->create())->get(route('recipes.review', $draft))->assertForbidden();

        // Step 3: save it, stocking the ingredients on the way.
        $this->actingAs($user)->post(route('recipes.finish', $draft), ['stock' => '1'])->assertRedirect(route('recipes.show', $draft));
        $recipe = Recipe::firstOrFail();
        $this->assertFalse($recipe->draft);
        $this->assertSame(5, Stock::where('user_id', $user->id)->count());
        $this->assertTrue(CartItem::where('user_id', $user->id)->where('product_id', $product->id)->exists());
        $this->get(route('recipes.review', $draft))->assertNotFound();

        // Importing the same link again now opens the saved copy.
        $this->post('/recipes/import', ['url' => self::PAGE])->assertRedirect(route('recipes.show', $recipe));
        $this->assertDatabaseCount('recipes', 1);
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Maria Santos')->assertSee('SS Eggplant')->assertSee('Fry the eggplant until golden.');
    }

    public function test_the_wizard_prefers_what_is_already_in_my_pantry_and_keeps_it_apart_from_the_catalog(): void
    {
        Storage::fake('public');
        $this->catalog();
        Http::fake([
            '93.184.216.34/images/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/*' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();
        $native = Product::create(['sku' => 'NG', 'category' => 'Fresh Produce', 'name' => 'Native Garlic', 'size' => '250g', 'price' => 120]);
        Stock::create(['user_id' => $user->id, 'product_id' => $native->id, 'quantity' => 3]);
        // Only loosely like "fresh turmeric root": suggested from the pantry, never picked by itself.
        $turmeric = Product::create(['sku' => 'TP', 'category' => 'Pantry', 'name' => 'Turmeric Powder', 'price' => 45]);
        Stock::create(['user_id' => $user->id, 'product_id' => $turmeric->id, 'quantity' => 1]);
        // Someone else's pantry is not mine.
        $peeled = Product::create(['sku' => 'PG', 'category' => 'Fresh Produce', 'name' => 'Peeled Garlic', 'price' => 39]);
        Stock::create(['user_id' => User::factory()->create()->id, 'product_id' => $peeled->id, 'quantity' => 1]);

        $this->actingAs($user)->post('/recipes/import', ['url' => self::PAGE]);
        $draft = Recipe::drafts()->with('ingredients.product')->firstOrFail();

        // The garlic I have wins over the catalog's best match; the rest match as before.
        $matches = $draft->ingredients->mapWithKeys(fn ($i) => [$i->name => $i->skip ? 'skip' : $i->product?->name])->all();
        $this->assertSame('Native Garlic', $matches['Garlic']);
        $this->assertSame('SS Eggplant', $matches['Chinese eggplant']);
        $this->assertNull($matches['Fresh turmeric root']);

        $page = $this->get(route('recipes.review', $draft))->assertOk();
        $page->assertSeeInOrder(['5 cloves garlic, chopped', 'In your pantry', 'Native Garlic', 'At home: 3', 'Or from the catalog', 'Global Garlic'])
            ->assertSee('Using Native Garlic from your pantry (3 at home)')
            ->assertSee('1 from your pantry')
            ->assertSeeInOrder(['1 thumb fresh turmeric root', 'In your pantry', 'Turmeric Powder', 'At home: 1']);

        // Search too: my pantry first, marked, then the catalog.
        $this->getJson('/products/search?q=garlic')->assertOk()
            ->assertJsonPath('0.name', 'Native Garlic')
            ->assertJsonPath('0.pantry', true)
            ->assertJsonPath('0.atHome', 3)
            ->assertJsonPath('1.pantry', false);
        $this->assertNotContains('Native Garlic', collect($this->getJson('/products/search?q=garlic')->json())->where('pantry', false)->pluck('name'));
        $this->getJson('/products/search?q=turmeric+root')->assertJsonPath('0.name', 'Turmeric Powder')->assertJsonPath('0.pantry', true);

        // Nothing typed: the whole pantry to pick from.
        $this->assertSame(
            [['Native Garlic', true], ['Turmeric Powder', true]],
            collect($this->getJson('/products/search?q=')->json())->map(fn ($p) => [$p['name'], $p['pantry']])->all(),
        );
    }

    public function test_discarding_an_import_removes_the_draft_its_photo_and_the_chef_it_created(): void
    {
        Storage::fake('public');
        Http::fake([
            '93.184.216.34/images/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/*' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/html']),
        ]);
        Chef::create(['name' => 'Kept Kitchen']);
        $this->actingAs(User::factory()->create())->post('/recipes/import', ['url' => self::PAGE]);
        $draft = Recipe::drafts()->firstOrFail();

        $this->delete(route('recipes.discard', $draft))->assertRedirect(route('recipes.import'));

        $this->assertSame(0, Recipe::drafts()->count());
        Storage::disk('public')->assertMissing($draft->image_path);
        $this->assertSame(['Kept Kitchen'], Chef::pluck('name')->all());
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

        // Landing views get the full-bleed hero; filtered views keep the plain heading.
        $this->get('/recipes')->assertSee('class="has-hero hero-dark"', false);
        $this->get('/recipes?q=adobo')->assertDontSee('has-hero', false);
        $this->get('/')->assertSee('class="has-hero hero-light"', false)->assertSee('landing-hero', false);
        $this->get('/?category=Bakery')->assertDontSee('landing-hero', false);
        $this->get(route('chefs.show', $chef->fresh()))->assertSee('id="chef-editor"', false)->assertSee('No bio yet');
    }

    public function test_chef_page_photo_can_be_uploaded_kept_and_replaced_by_a_recipe_photo(): void
    {
        Storage::fake('public');
        $chef = Chef::create(['name' => 'Vanjo Merano']);
        $recipe = Recipe::create(['title' => 'Adobong Talong', 'chef_id' => $chef->id, 'image_path' => 'recipes/a.webp']);
        $this->actingAs($this->admin());

        $this->put(route('chefs.update', $chef), [
            'name' => 'Vanjo Merano',
            'bio' => "Home cook from Batangas.\n\nKnown for adobo.",
            'hero_recipe_id' => '',
            'hero_photo' => UploadedFile::fake()->image('kitchen.jpg', 3600, 2000),
        ])->assertRedirect(route('chefs.show', $chef));

        $chef->refresh();
        $this->assertStringStartsWith('chefs/', $chef->hero_image_path);
        $this->assertStringEndsWith('.webp', $chef->hero_image_path);
        Storage::disk('public')->assertExists($chef->hero_image_path);
        $this->assertSame(2400, getimagesizefromstring(Storage::disk('public')->get($chef->hero_image_path))[0]);
        $this->assertSame($chef->hero_photo, $chef->heroImage());
        $this->get(route('chefs.show', $chef))->assertSee($chef->hero_image_path, false)->assertSee('<p>Known for adobo.</p>', false);

        // Saving again with "Your photo" keeps it.
        $photo = $chef->hero_image_path;
        $this->put(route('chefs.update', $chef), ['name' => 'Vanjo Merano', 'hero_recipe_id' => 'photo'])->assertSessionHasNoErrors();
        $this->assertSame($photo, $chef->fresh()->hero_image_path);

        // Choosing a recipe photo removes the uploaded one.
        $this->put(route('chefs.update', $chef), ['name' => 'Vanjo Merano', 'hero_recipe_id' => $recipe->id])->assertSessionHasNoErrors();
        $chef->refresh();
        $this->assertNull($chef->hero_image_path);
        $this->assertSame($recipe->id, $chef->hero_recipe_id);
        Storage::disk('public')->assertMissing($photo);

        // Not an image: refused.
        $this->put(route('chefs.update', $chef), ['name' => 'Vanjo Merano', 'hero_photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('hero_photo');

        // Removing the chef removes their uploaded photo too.
        $this->put(route('chefs.update', $chef), ['name' => 'Vanjo Merano', 'hero_photo' => UploadedFile::fake()->image('again.png', 800, 500)]);
        $photo = $chef->fresh()->hero_image_path;
        $this->delete(route('chefs.destroy', $chef))->assertRedirect('/chefs');
        Storage::disk('public')->assertMissing($photo);
    }

    public function test_only_the_importer_or_an_admin_can_delete_recipes_and_admins_remove_chefs(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $chef = Chef::create(['name' => 'Vanjo Merano']);
        $recipe = Recipe::create(['title' => 'Adobo', 'user_id' => $owner->id, 'chef_id' => $chef->id]);

        // The recipe page offers Delete only to whoever can use it.
        $this->actingAs($other)->get(route('recipes.show', $recipe))->assertDontSee('Delete recipe');
        $this->actingAs($owner)->get(route('recipes.show', $recipe))->assertSee('Delete recipe');

        $this->actingAs($other)->delete(route('recipes.destroy', $recipe))->assertForbidden();
        $this->delete(route('chefs.destroy', $chef))->assertForbidden();
        $this->get(route('chefs.show', $chef))->assertDontSee('Delete chef');
        $this->actingAs($this->admin())->get(route('chefs.show', $chef))->assertSee('Delete chef')->assertSee('Delete chef and recipe');
        $this->delete(route('chefs.destroy', $chef))->assertRedirect('/chefs');
        $this->assertNull($recipe->fresh()->chef_id);

        $this->actingAs($owner)->delete(route('recipes.destroy', $recipe))->assertRedirect('/recipes');
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_a_chef_can_be_deleted_with_their_recipes_by_whoever_added_them_all(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $chef = Chef::create(['name' => 'Cassie Best']);
        Storage::disk('public')->put('recipes/pancakes.webp', 'x');
        Storage::disk('public')->put('chefs/kitchen.webp', 'x');
        $chef->hero_image_path = 'chefs/kitchen.webp';
        $chef->save();
        Recipe::create(['title' => 'Easy pancakes', 'user_id' => $owner->id, 'chef_id' => $chef->id, 'image_path' => 'recipes/pancakes.webp']);
        Recipe::create(['title' => 'Crepes', 'user_id' => $owner->id, 'chef_id' => $chef->id]);
        Recipe::create(['title' => 'Half imported', 'user_id' => $owner->id, 'chef_id' => $chef->id, 'draft' => true]);
        $kept = Recipe::create(['title' => 'Someone else', 'user_id' => $owner->id]);

        // Every recipe is theirs, so the person who added them may delete the chef, recipes and all.
        $this->actingAs($owner)->get(route('chefs.show', $chef))->assertSee('Delete chef and 2 recipes');
        $this->delete(route('chefs.destroy', $chef), ['with_recipes' => '1'])
            ->assertRedirect('/chefs')
            ->assertSessionHas('success', 'Cassie Best deleted, with their 2 recipes.');

        $this->assertModelMissing($chef);
        $this->assertSame([$kept->id], Recipe::withoutGlobalScope('published')->pluck('id')->all());
        Storage::disk('public')->assertMissing('recipes/pancakes.webp');
        Storage::disk('public')->assertMissing('chefs/kitchen.webp');

        // A chef with someone else's recipe stays an admin's call.
        $shared = Chef::create(['name' => 'Shared Kitchen']);
        Recipe::create(['title' => 'Theirs', 'user_id' => User::factory()->create()->id, 'chef_id' => $shared->id]);
        $this->delete(route('chefs.destroy', $shared), ['with_recipes' => '1'])->assertForbidden();
        $this->assertModelExists($shared);
    }
}
