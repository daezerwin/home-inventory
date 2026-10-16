<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ImageDownloader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadProductImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 40;

    public function __construct(public int $productId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ImageDownloader $downloader): void
    {
        $product = Product::find($this->productId);
        if ($product && $product->image_url && ! $product->image_path) {
            $downloader->download($product);
        }
    }
}
