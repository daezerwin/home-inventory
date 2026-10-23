<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadProductImage;
use App\Models\Product;
use App\Services\ImageDownloader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Adding and editing catalog products (and fetching a product's photo), for
 * everyone in the household. Deleting products, CSV imports, bulk photo
 * downloads and resets stay with admins.
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

    public function store(Request $request, ImageDownloader $images)
    {
        $data = $this->validated($request);
        // A product added by hand needs no SKU; the CSV's SKUs never start like this.
        $data['sku'] ??= 'USR-'.Str::upper(Str::random(8));
        $product = Product::create($data);
        $photo = $this->downloadPhoto($product, $images);

        // Straight to its card, ready for My pantry or the shopping list.
        return redirect()->route('catalog', ['q' => $product->name])->with('success', "{$product->name} added to the catalog.{$photo}");
    }

    public function update(Request $request, Product $product, ImageDownloader $images)
    {
        $data = $this->validated($request, $product);
        $data['sku'] ??= $product->sku;

        if ($product->image_url !== ($data['image_url'] ?? null)) {
            $data['image_path'] = null;
        }

        $product->update($data);
        $photo = $this->downloadPhoto($product, $images);

        return redirect()->to($this->returnUrl($request->input('return')))->with('success', "{$product->name} saved.{$photo}");
    }

    /** "Download photo now" on the edit form: fetches it while you wait, or says why it couldn't. */
    public function downloadNow(Product $product, ImageDownloader $images)
    {
        if (! $product->image_url) {
            throw ValidationException::withMessages(['image_url' => 'Add a photo link first, then save.']);
        }

        try {
            $images->download($product);
        } catch (RuntimeException|HttpClientException $e) {
            throw ValidationException::withMessages(['image_url' => "The photo couldn't be downloaded: {$this->reason($e)}"]);
        }

        return back()->with('success', "Photo of {$product->name} downloaded.");
    }

    /**
     * A single product's photo is downloaded right away, so it shows as soon as
     * the product is saved. If the site doesn't cooperate, the background queue
     * keeps trying. Returns a note for the saved message.
     */
    private function downloadPhoto(Product $product, ImageDownloader $images): string
    {
        if (! $product->image_url || $product->image_path) {
            return '';
        }

        try {
            $images->download($product);

            return ' Its photo is downloaded.';
        } catch (RuntimeException|HttpClientException) {
            DownloadProductImage::dispatch($product->id);

            return " The photo couldn't be downloaded just now; it keeps trying in the background.";
        }
    }

    /** Why a photo download failed, in plain words. */
    private function reason(RuntimeException|HttpClientException $e): string
    {
        return match (true) {
            $e instanceof RequestException => "the site answered with error {$e->response->status()}.",
            $e instanceof ConnectionException => "the site didn't answer in time.",
            default => lcfirst($e->getMessage()),
        };
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
