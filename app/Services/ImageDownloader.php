<?php

namespace App\Services;

use App\Models\Product;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Downloads remote photos into public storage so the app never hotlinks the
 * source site. Photos are scaled down and re-encoded as WebP when GD is
 * available, which keeps a 14k-product catalog to a few hundred MB.
 */
class ImageDownloader
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private SafeHttp $http) {}

    public function download(Product $product): void
    {
        $url = $product->image_url;

        if (! $url) {
            return;
        }

        $path = $this->store($url, 'products', config('inventory.image_max_size'), config('inventory.image_hosts'));

        // Ignore a download that finished after the catalog URL changed.
        Product::whereKey($product->id)->where('image_url', $url)->update(['image_path' => $path]);
    }

    /**
     * Downloads an image into public storage and returns its path there.
     *
     * @param  list<string>  $allowedHosts  empty = any public host
     */
    public function store(string $url, string $directory, int $maxSize, array $allowedHosts = []): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'pantry-image-');

        try {
            [$response] = $this->http->get($url, self::MAX_BYTES, $temporary, $allowedHosts);
            $response->throw();

            if ($response->status() !== 200) {
                throw new RuntimeException("Image server answered with HTTP {$response->status()}.");
            }

            return $this->storeFile($temporary, $directory, $maxSize, hash('sha256', $url));
        } finally {
            @unlink($temporary);
        }
    }

    /** Scales and re-encodes a local image (a download or an upload) into public storage. */
    public function storeFile(string $file, string $directory, int $maxSize, string $name): string
    {
        [$contents, $extension] = $this->prepare($file, $maxSize);
        $path = $directory.'/'.$name.'.'.$extension;
        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    /**
     * @return array{0: string, 1: string} file contents and extension
     */
    private function prepare(string $file, int $maxSize): array
    {
        $extension = match (@getimagesize($file)['mime'] ?? null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new RuntimeException('Downloaded file is not a supported image.'),
        };

        if ($maxSize > 0 && function_exists('imagewebp')) {
            $image = @imagecreatefromstring(file_get_contents($file));

            if ($image instanceof GdImage) {
                return [$this->toWebp($image, $maxSize), 'webp'];
            }
        }

        return [file_get_contents($file), $extension];
    }

    private function toWebp(GdImage $image, int $maxSize): string
    {
        // Product shots are shown on light cards; keep transparency intact.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSize / max($width, $height));

        if ($scale < 1) {
            $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 255, 255, 255, 127));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            $image = $resized;
        }

        ob_start();
        imagewebp($image, null, 80);

        return ob_get_clean();
    }
}
