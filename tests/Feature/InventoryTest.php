<?php

namespace Tests\Feature;

use App\Jobs\DownloadProductImage;
use App\Models\CartItem;
use App\Models\Product;
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
        foreach (['/', '/?view=list', '/pantry', '/pantry?status=low', '/shopping-list', '/admin', '/admin/products/create', '/admin/products/'.$product->id.'/edit'] as $url) {
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
        $this->actingAs($user)->post('/admin/products', $data)->assertRedirect('/admin');
        $product = Product::first();
        Queue::assertPushed(DownloadProductImage::class);
        $this->put('/admin/products/'.$product->id, array_merge($data, ['name' => 'Hand soap']))->assertRedirect('/admin');
        $this->assertDatabaseHas('products', ['name' => 'Hand soap']);
        $this->delete('/admin/products/'.$product->id)->assertRedirect();
        $this->assertDatabaseCount('products', 0);
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
