<?php

namespace Tests\Feature;

use App\Jobs\DownloadProductImage;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\User;
use App\Services\CsvImporter;
use App\Services\ImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $data = []): Product
    {
        return Product::create(array_merge(['sku' => 'SKU-001', 'name' => 'Everyday rice', 'category' => 'Pantry', 'type' => 'Rice', 'size' => '1kg', 'price' => 62.50], $data));
    }

    private function csv(string $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pantry-csv');
        file_put_contents($path, "sku,group,name,image,type,size,price\n".$rows);

        return $path;
    }

    public function test_guests_are_sent_to_login_and_first_account_is_admin(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertSee('Create the first administrator');
        $this->post('/setup', ['name' => 'Home Owner', 'email' => 'owner@example.com', 'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password'])->assertRedirect('/');
        $this->assertTrue(User::first()->is_admin);
        $this->post('/logout');
        $this->post('/setup', ['name' => 'Other', 'email' => 'other@example.com', 'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_catalog_and_all_main_pages_render_with_owned_records(): void
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $product = $this->product();
        Stock::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2, 'target_quantity' => 3]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1]);
        foreach (['/', '/?view=list', '/pantry', '/pantry?status=low', '/shopping-list', '/admin', '/products/create', '/products/'.$product->id.'/edit'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->get('/?q=missing')->assertDontSee('Everyday rice');
        $this->get('/?category=Pantry')->assertSee('Everyday rice');
    }

    public function test_regular_users_cannot_manage_the_shared_catalog(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->delete('/admin/products/'.$product->id)->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_admin_can_create_update_and_delete_products(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $data = ['sku' => 'P2', 'category' => 'Home Care', 'name' => 'Soap', 'image_url' => 'https://smmarkets.ph/soap.png', 'type' => 'Soap', 'size' => '100g', 'price' => 25];
        $this->actingAs($user)->post('/products', $data)->assertRedirect('/?q=Soap');
        $product = Product::first();
        Queue::assertPushed(DownloadProductImage::class);
        $this->put('/products/'.$product->id, array_merge($data, ['name' => 'Hand soap', 'return' => url('/admin')]))->assertRedirect('/admin');
        $this->assertDatabaseHas('products', ['name' => 'Hand soap']);
        $this->delete('/admin/products/'.$product->id)->assertRedirect();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_everyone_can_add_and_edit_products_from_discover_but_only_admins_delete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = $this->product();

        // Discover offers it: an add button, an edit pencil per card, and "Add …" when a search finds nothing.
        $this->actingAs($user)->get('/?category=Pantry')->assertSee(route('products.create'), false)->assertSee(route('products.edit', $product), false);
        $this->get('/?q=Oat+milk')->assertSee(route('products.create', ['name' => 'Oat milk']), false)->assertSee('Add “Oat milk”');
        $this->get('/products/create?name=Oat+milk')->assertOk()->assertSee('value="Oat milk"', false)->assertDontSee('Delete product');

        // No SKU needed; one is made up.
        $this->post('/products', ['name' => 'Oat milk', 'category' => 'Chilled & Dairy Items', 'price' => '120'])->assertRedirect(route('catalog', ['q' => 'Oat milk']));
        $oat = Product::where('name', 'Oat milk')->firstOrFail();
        $this->assertMatchesRegularExpression('/^USR-[A-Z0-9]{8}$/', $oat->sku);

        // Editing keeps the SKU when it is left blank, and returns to where the form was opened.
        $this->from('/?category=Pantry')->get(route('products.edit', $product))->assertOk()->assertSee('value="'.url('/?category=Pantry').'"', false);
        $this->put(route('products.update', $product), ['name' => 'Jasmine rice', 'category' => 'Pantry', 'price' => '70', 'return' => url('/?category=Pantry')])
            ->assertRedirect('/?category=Pantry');
        $this->assertSame(['Jasmine rice', 'SKU-001'], [$product->fresh()->name, $product->fresh()->sku]);
        // Only pages of this app count as "where you came from".
        $this->put(route('products.update', $product), ['name' => 'Jasmine rice', 'category' => 'Pantry', 'price' => '70', 'return' => 'https://example.com/'])->assertRedirect('/');

        $this->delete('/admin/products/'.$product->id)->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_tracking_and_adjusting_stock_is_personal_and_never_negative(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user)->post('/pantry/'.$product->id)->assertRedirect();
        $this->put('/pantry/'.$product->id, ['quantity' => 1, 'target_quantity' => 4, 'location' => 'Kitchen'])->assertRedirect();
        $this->post('/pantry/'.$product->id.'/adjust', ['delta' => -1]);
        $this->postJson('/pantry/'.$product->id.'/adjust', ['delta' => -1])
            ->assertOk()
            ->assertJson(['quantity' => 0, 'target' => 4, 'message' => 'There is no stock to use.']);
        $this->postJson('/pantry/'.$product->id.'/adjust', ['delta' => 1])->assertJson(['quantity' => 1]);
        $this->postJson('/pantry/'.$product->id.'/adjust', ['delta' => 5])->assertUnprocessable();
        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'quantity' => 1, 'target_quantity' => 4]);
        $this->actingAs($other)->get('/pantry')->assertDontSee('Everyday rice');
        $this->post('/pantry/'.$product->id.'/adjust', ['delta' => -1])->assertNotFound();
        $this->assertDatabaseCount('stocks', 1);
        $this->put('/pantry/'.$product->id, ['quantity' => -1, 'target_quantity' => 0])->assertSessionHasErrors(['quantity', 'target_quantity']);
    }

    public function test_restocking_is_repeatable_and_uses_target_shortfall(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Stock::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2, 'target_quantity' => 5]);
        $this->actingAs($user)->post('/pantry/restock')->assertRedirect('/shopping-list');
        $this->post('/pantry/restock');
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['quantity' => 3]);
        CartItem::first()->update(['quantity' => 6]);
        $this->post('/pantry/restock');
        $this->assertDatabaseHas('cart_items', ['quantity' => 6]);
    }

    public function test_checked_checkout_adds_stock_once_and_preserves_unchecked_items(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $second = $this->product(['sku' => 'P2', 'name' => 'Coffee']);
        Stock::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2, 'target_quantity' => 5]);
        $bought = CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 3, 'checked' => true]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $second->id, 'quantity' => 1]);
        $this->actingAs($user)->post('/shopping-list/checkout')->assertRedirect();
        $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'quantity' => 5]);
        $this->assertDatabaseMissing('cart_items', ['id' => $bought->id]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $second->id]);
        $this->post('/shopping-list/checkout');
        $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'quantity' => 5]);
    }

    public function test_cart_actions_are_isolated_and_add_to_existing_quantity(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user)->post('/shopping-list/product/'.$product->id, ['quantity' => 2]);
        $this->post('/shopping-list/product/'.$product->id);
        $item = CartItem::first();
        $this->assertEquals(3, $item->quantity);
        $this->patch('/shopping-list/'.$item->id, ['checked' => 1]);
        $this->assertEquals(0, Stock::count());
        $this->actingAs($other)->patch('/shopping-list/'.$item->id, ['quantity' => 50])->assertNotFound();
        $this->delete('/shopping-list/'.$item->id)->assertNotFound();
        $this->post('/shopping-list/checkout');
        $this->assertDatabaseCount('stocks', 0);
        $this->get('/shopping-list')->assertDontSee('Everyday rice');
    }

    public function test_import_upserts_skus_preserves_personal_stock_and_queues_photos(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $product = $this->product(['image_url' => 'https://smmarkets.ph/old.png', 'image_path' => 'products/old.png']);
        Stock::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 8, 'target_quantity' => 10]);
        $path = $this->csv("SKU-001,Pantry,New rice,https://smmarkets.ph/new.png,Rice,2kg,100\nP2,Pantry,Coffee,,Coffee,100g,50\n");
        try {
            $this->assertEquals(2, app(CsvImporter::class)->import($path)['imported']);
            app(CsvImporter::class)->import($path);
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-001', 'name' => 'New rice', 'image_path' => null]);
        $this->assertDatabaseHas('stocks', ['quantity' => 8, 'target_quantity' => 10]);
        Queue::assertPushed(DownloadProductImage::class);
    }

    public function test_invalid_import_is_atomic(): void
    {
        Queue::fake();
        $path = $this->csv("P1,Pantry,Rice,,Rice,1kg,20\nP2,Pantry,Coffee,,Coffee,1kg,-20\n");
        try {
            app(CsvImporter::class)->import($path);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Row 3', $e->getMessage());
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('products', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_skus_are_rejected_before_import(): void
    {
        $path = $this->csv("P1,Pantry,Rice,,Rice,1kg,20\nP1,Pantry,Coffee,,Coffee,1kg,20\n");
        try {
            app(CsvImporter::class)->import($path);
            $this->fail('Expected duplicate error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate SKU', $e->getMessage());
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_image_downloader_rejects_private_and_unapproved_urls(): void
    {
        foreach (['https://127.0.0.1/internal', 'http://10.0.0.5/photo.png', 'file:///etc/passwd', 'https://93.184.216.34:8443/photo.png'] as $url) {
            try {
                app(ImageDownloader::class)->download($this->product(['sku' => md5($url), 'image_url' => $url]));
                $this->fail("Expected {$url} to be refused");
            } catch (\RuntimeException) {
                // refused as expected
            }
        }

        config(['inventory.image_hosts' => ['smmarkets.ph']]);
        $this->expectExceptionMessage('not in INVENTORY_IMAGE_HOSTS');
        app(ImageDownloader::class)->download($this->product(['sku' => 'P9', 'image_url' => 'https://93.184.216.34/photo.png']));
    }

    public function test_image_downloader_stores_a_resized_webp_copy(): void
    {
        Storage::fake('public');
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 60));
        ob_start();
        imagepng($image);
        Http::fake(['*' => Http::response(ob_get_clean(), 200, ['Content-Type' => 'image/png'])]);

        // An IP literal skips DNS, so the test runs offline.
        $product = $this->product(['image_url' => 'https://93.184.216.34/rice.png']);
        app(ImageDownloader::class)->download($product);

        $path = $product->fresh()->image_path;
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([600, 400], [$width, $height]);
    }

    public function test_quick_actions_answer_json_for_in_place_updates(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actingAs($user)->postJson('/shopping-list/product/'.$product->id)
            ->assertOk()
            ->assertJson(['quantity' => 1, 'cartCount' => 1]);
        $item = CartItem::first();
        $this->patchJson('/shopping-list/'.$item->id, ['checked' => 1])
            ->assertJson(['checked' => true, 'cartCount' => 0]);
        $this->patchJson('/shopping-list/'.$item->id, ['quantity' => 4])->assertJson(['quantity' => 4]);
        $this->deleteJson('/shopping-list/'.$item->id)->assertJson(['cartCount' => 0]);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_admin_photo_status_and_import_are_admin_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/admin/status')->assertForbidden();
        $this->post('/admin/import')->assertForbidden();

        $user->is_admin = true;
        $user->save();
        $this->product(['image_url' => 'https://smmarkets.ph/rice.png']);
        $this->getJson('/admin/status')->assertOk()->assertJson(['total' => 1, 'withPhotoUrl' => 1, 'localImages' => 0]);
    }

    public function test_admin_can_queue_missing_photos_and_download_them_on_demand(): void
    {
        Storage::fake('public');
        config(['queue.default' => 'database']);
        $image = imagecreatetruecolor(800, 800);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        // A fresh response per download: a shared one's body is used up by the first.
        Http::fake(['*' => fn () => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();

        // One photo never downloaded, one whose file has gone missing, one fine,
        // and one whose path is stale but whose earlier download is still on disk.
        $new = $this->product(['sku' => 'A', 'image_url' => 'https://93.184.216.34/a.png']);
        $gone = $this->product(['sku' => 'B', 'image_url' => 'https://93.184.216.34/b.png', 'image_path' => 'products/gone.webp']);
        Storage::disk('public')->put('products/kept.webp', 'x');
        $this->product(['sku' => 'C', 'image_url' => 'https://93.184.216.34/c.png', 'image_path' => 'products/kept.webp']);
        $onDisk = 'products/'.hash('sha256', 'https://93.184.216.34/d.png').'.webp';
        Storage::disk('public')->put($onDisk, 'x');
        $relinked = $this->product(['sku' => 'D', 'image_url' => 'https://93.184.216.34/d.png', 'image_path' => 'products/old-name.png']);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('downloaded')->assertSee('Download now');
        $this->post('/admin/images/retry')->assertSessionHas('success', '2 photos queued for download.');
        $this->assertNull($gone->fresh()->image_path);
        $this->assertSame($onDisk, $relinked->fresh()->image_path);
        $this->assertDatabaseCount('jobs', 2);

        $this->postJson('/admin/images/run')->assertOk()->assertJson(['downloaded' => 2, 'queued' => 0, 'localImages' => 4]);
        Storage::disk('public')->assertExists($new->fresh()->image_path);
        Storage::disk('public')->assertExists($gone->fresh()->image_path);

        $this->actingAs(User::factory()->create())->postJson('/admin/images/run')->assertForbidden();
    }

    public function test_my_pantry_lists_only_the_groups_in_my_pantry(): void
    {
        $user = User::factory()->create();
        $rice = $this->product(['sku' => 'A', 'category' => 'Pantry']);
        $this->product(['sku' => 'B', 'category' => 'Pantry', 'name' => 'Brown rice']);
        $this->product(['sku' => 'C', 'category' => 'Snacks', 'name' => 'Chips']);
        Stock::create(['user_id' => $user->id, 'product_id' => $rice->id, 'quantity' => 1]);
        // Someone else's pantry doesn't count.
        Stock::create(['user_id' => User::factory()->create()->id, 'product_id' => Product::where('sku', 'C')->value('id'), 'quantity' => 1]);

        $this->actingAs($user)->get('/pantry')->assertOk()
            ->assertViewHas('categories', fn ($groups) => $groups->pluck('total', 'category')->all() === ['Pantry' => 1]);
        $this->get('/')->assertViewHas('categories', fn ($groups) => $groups->pluck('total', 'category')->all() === ['Pantry' => 2, 'Snacks' => 1]);
    }

    public function test_admin_can_reset_every_pantry_and_the_whole_catalog(): void
    {
        Storage::fake('public');
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        Storage::disk('public')->put('products/rice.webp', 'x');
        Storage::disk('public')->put('recipes/adobo.webp', 'x');
        $product = $this->product(['image_url' => 'https://93.184.216.34/rice.png', 'image_path' => 'products/rice.webp']);
        foreach ([$user, $admin] as $person) {
            Stock::create(['user_id' => $person->id, 'product_id' => $product->id, 'quantity' => 2]);
            CartItem::create(['user_id' => $person->id, 'product_id' => $product->id]);
        }
        $recipe = Recipe::create(['title' => 'Adobo', 'image_path' => 'recipes/adobo.webp']);
        $ingredient = $recipe->ingredients()->create(['line' => '1 cup rice', 'name' => 'Rice', 'product_id' => $product->id]);
        DownloadProductImage::dispatch($product->id);

        $this->actingAs($user)->post('/admin/reset/pantries', ['confirmation' => 'RESET'])->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertSee('Reset pantries')->assertSee('Reset catalog');

        // The word has to be typed.
        $this->post('/admin/reset/pantries', ['confirmation' => 'reset'])->assertSessionHasErrors(['confirmation' => 'Type RESET to confirm.']);
        $this->assertDatabaseCount('stocks', 2);

        $this->post('/admin/reset/pantries', ['confirmation' => 'RESET'])->assertSessionHas('success', '2 pantry items deleted.');
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->post('/admin/reset/pantries', ['confirmation' => 'RESET', 'with_lists' => '1'])->assertSessionHas('success', '0 pantry items deleted, and 2 shopping list items.');
        $this->assertDatabaseCount('cart_items', 0);

        $this->post('/admin/reset/catalog')->assertSessionHasErrors('confirmation');
        $this->post('/admin/reset/catalog', ['confirmation' => 'RESET'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('jobs', 0);
        Storage::disk('public')->assertMissing('products/rice.webp');
        // Recipes stay, with their photo; the ingredient waits to be picked again.
        $this->assertModelExists($recipe);
        Storage::disk('public')->assertExists('recipes/adobo.webp');
        $this->assertNull($ingredient->fresh()->product_id);
    }

    public function test_product_deletion_cascades_stock_and_cart_records(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Stock::create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $product->id]);
        $product->delete();
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }
}
