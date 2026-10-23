<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadProductImage;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\RecipeIngredient;
use App\Models\Stock;
use App\Services\CsvImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->filled('q')) {
            $term = '%'.addcslashes(mb_substr($request->string('q')->trim()->toString(), 0, 120), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        match ($request->input('photo')) {
            'missing' => $query->whereNull('image_path'),
            'local' => $query->whereNotNull('image_path'),
            default => null,
        };

        return view('admin.index', [
            'products' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'categoryOptions' => Product::distinct()->orderBy('category')->pluck('category'),
            'pantryCount' => Stock::count(),
            'listCount' => CartItem::count(),
            ...$this->photoStatus(),
        ]);
    }

    /** Polled by the admin page while photos download in the background. */
    public function status()
    {
        return response()->json($this->photoStatus());
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.index')->with('success', "{$product->name} deleted from the catalog, pantries and shopping lists.");
    }

    public function import(Request $request, CsvImporter $importer)
    {
        $request->validate(['csv' => 'required|file|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel|max:20480']);
        set_time_limit(300);

        $result = $importer->import($request->file('csv')->getRealPath());

        return back()->with('success', number_format($result['imported']).' products imported. Photos are downloading in the background.');
    }

    public function retryImages()
    {
        DB::table('failed_jobs')->where('payload', 'like', '%DownloadProductImage%')->delete();
        DB::table('jobs')->where('payload', 'like', '%DownloadProductImage%')->delete();

        // A photo whose file is gone (say, after restoring an older database) is relinked to
        // its earlier download when that is still on disk, or else downloads again.
        Product::whereNotNull('image_path')->select('id', 'image_url', 'image_path')->chunkById(1000, function ($products) {
            foreach ($products->reject(fn (Product $product) => Storage::disk('public')->exists($product->image_path)) as $product) {
                $product->update(['image_path' => $this->downloadedPhoto($product)]);
            }
        });
        Product::whereNotNull('image_url')->whereNull('image_path')->select('id', 'image_url')->chunkById(1000, function ($products) {
            foreach ($products as $product) {
                if ($path = $this->downloadedPhoto($product)) {
                    $product->update(['image_path' => $path]);
                }
            }
        });

        $queued = 0;
        Product::whereNotNull('image_url')->whereNull('image_path')->select('id')->chunkById(500, function ($products) use (&$queued) {
            foreach ($products as $product) {
                DownloadProductImage::dispatch($product->id);
                $queued++;
            }
        });

        return back()->with('success', $queued ? number_format($queued).' photos queued for download.' : 'Every photo is already downloaded.');
    }

    /**
     * Works through the photo queue for a few seconds right in this request, so
     * photos arrive even when no background worker is running. The admin page
     * calls it again and again while "Download now" is on.
     */
    public function runImages(Request $request)
    {
        set_time_limit(120);
        $before = Product::whereNotNull('image_path')->count();

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 20,
            '--tries' => 3,
            '--timeout' => 45,
            '--quiet' => true,
        ]);

        $status = $this->photoStatus();
        $downloaded = max(0, $status['localImages'] - $before);

        if ($request->expectsJson()) {
            return response()->json([...$status, 'downloaded' => $downloaded]);
        }

        return back()->with('success', number_format($downloaded).' photos downloaded. '.($status['queued'] ? number_format($status['queued']).' still queued.' : 'The queue is empty.'));
    }

    /** Empties every pantry, and every shopping list too when asked. Products and recipes stay. */
    public function resetPantries(Request $request)
    {
        $this->confirmReset($request);

        $pantry = Stock::query()->delete();
        $lists = $request->boolean('with_lists') ? CartItem::query()->delete() : 0;

        return back()->with('success', number_format($pantry).' pantry items deleted'.($lists ? ', and '.number_format($lists).' shopping list items.' : '.'));
    }

    /**
     * Deletes the whole catalog and its photos, and with it every pantry and
     * shopping list. Recipes stay; their ingredients are picked again later.
     */
    public function resetCatalog(Request $request)
    {
        $this->confirmReset($request);
        $count = Product::count();

        DB::table('jobs')->where('payload', 'like', '%DownloadProductImage%')->delete();
        DB::table('failed_jobs')->where('payload', 'like', '%DownloadProductImage%')->delete();
        DB::transaction(function () {
            Stock::query()->delete();
            CartItem::query()->delete();
            RecipeIngredient::whereNotNull('product_id')->update(['product_id' => null]);
            Product::query()->delete();
        });
        Storage::disk('public')->deleteDirectory('products');

        return back()->with('success', number_format($count).' products deleted, with every pantry and shopping list. Import a CSV to fill the catalog again.');
    }

    /** Resets are typed out ("RESET"), so a stray tap or a resent form can't wipe everything. */
    private function confirmReset(Request $request): void
    {
        $request->validate(['confirmation' => 'required|in:RESET'], ['confirmation.*' => 'Type RESET to confirm.']);
    }

    /** The file an earlier download of this product's photo left, named as ImageDownloader names it. */
    private function downloadedPhoto(Product $product): ?string
    {
        if (! $product->image_url) {
            return null;
        }

        $name = 'products/'.hash('sha256', $product->image_url);

        foreach (['webp', 'jpg', 'png', 'gif'] as $extension) {
            if (Storage::disk('public')->exists("{$name}.{$extension}")) {
                return "{$name}.{$extension}";
            }
        }

        return null;
    }

    /** @return array{total: int, withPhotoUrl: int, localImages: int, queued: int, failed: int} */
    private function photoStatus(): array
    {
        return [
            'total' => Product::count(),
            'withPhotoUrl' => Product::whereNotNull('image_url')->count(),
            'localImages' => Product::whereNotNull('image_path')->count(),
            'queued' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
        ];
    }
}
