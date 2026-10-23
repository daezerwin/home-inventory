<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadProductImage;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Adding and editing catalog products, for everyone in the household.
 * Deleting products (and CSV imports, photo downloads, resets) stay with admins.
 */
class ProductController extends Controller
{
    public function create(Request $request)
    {
        // "Add “oat milk”" from an empty search starts with that name.
        $product = new Product(['name' => mb_substr($request->string('name')->trim()->toString(), 0, 255)]);

        return view('products.form', ['product' => $product, 'return' => $this->returnUrl(url()->previous())]);
    }

    public function edit(Product $product)
    {
        return view('products.form', ['product' => $product, 'return' => $this->returnUrl(url()->previous())]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // A product added by hand needs no SKU; the CSV's SKUs never start like this.
        $data['sku'] ??= 'USR-'.Str::upper(Str::random(8));
        $product = Product::create($data);

        if ($product->image_url) {
            DownloadProductImage::dispatch($product->id);
        }

        // Straight to its card, ready for My pantry or the shopping list.
        return redirect()->route('catalog', ['q' => $product->name])->with('success', "{$product->name} added to the catalog.");
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);
        $data['sku'] ??= $product->sku;

        if ($product->image_url !== ($data['image_url'] ?? null)) {
            $data['image_path'] = null;
        }

        $product->update($data);

        if ($product->image_url && ! $product->image_path) {
            DownloadProductImage::dispatch($product->id);
        }

        return redirect()->to($this->returnUrl($request->input('return')))->with('success', "{$product->name} saved.");
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($product?->id)],
            'category' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'image_url' => 'nullable|url:http,https|max:2048',
            'type' => 'nullable|string|max:255',
            'size' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0|max:9999999999.99',
        ]);
    }

    /** Where to go after saving: the page the form was opened from, if it is one of ours. */
    private function returnUrl(?string $url): string
    {
        $ours = parse_url(url('/'));
        $there = parse_url((string) $url) ?: [];
        $sameSite = $url && array_intersect_key($there, ['scheme' => 0, 'host' => 0, 'port' => 0]) === array_intersect_key($ours, ['scheme' => 0, 'host' => 0, 'port' => 0]);
        $form = str_starts_with($there['path'] ?? '', '/products/');

        return $sameSite && ! $form ? $url : route('catalog');
    }
}
