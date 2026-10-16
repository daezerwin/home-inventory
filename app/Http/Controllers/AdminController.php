<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadProductImage;
use App\Models\Product;
use App\Services\CsvImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            ...$this->photoStatus(),
        ]);
    }

    /** Polled by the admin page while photos download in the background. */
    public function status()
    {
        return response()->json($this->photoStatus());
    }

    public function create()
    {
        return view('admin.form', ['product' => new Product]);
    }

    public function edit(Product $product)
    {
        return view('admin.form', compact('product'));
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validated($request));

        if ($product->image_url) {
            DownloadProductImage::dispatch($product->id);
        }

        return redirect()->route('admin.index')->with('success', "{$product->name} created.");
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);

        if ($product->image_url !== ($data['image_url'] ?? null)) {
            $data['image_path'] = null;
        }

        $product->update($data);

        if ($product->image_url && ! $product->image_path) {
            DownloadProductImage::dispatch($product->id);
        }

        return redirect()->route('admin.index')->with('success', "{$product->name} saved.");
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

        Product::whereNotNull('image_url')->whereNull('image_path')->chunkById(500, function ($products) {
            foreach ($products as $product) {
                DownloadProductImage::dispatch($product->id);
            }
        });

        return back()->with('success', 'Missing photos queued for download.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:255', Rule::unique('products')->ignore($product?->id)],
            'category' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'image_url' => 'nullable|url:http,https|max:2048',
            'type' => 'nullable|string|max:255',
            'size' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0|max:9999999999.99',
        ]);
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
