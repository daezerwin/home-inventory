<?php

namespace App\Services;

use App\Models\Product;
use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Downloads a product's remote photo into public storage so the app never
 * hotlinks the shop. Photos are scaled down and re-encoded as WebP when GD
 * is available, which keeps a 14k-product catalog to a few hundred MB.
 */
class ImageDownloader
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function download(Product $product): void
    {
        $url = $product->image_url;

        if (! $url) {
            return;
        }

        [$host, $port, $ip] = $this->resolveSafely($url);
        $temporary = tempnam(sys_get_temp_dir(), 'pantry-image-');

        try {
            $response = Http::connectTimeout(5)
                ->timeout(25)
                ->withUserAgent('HomePantry/1.0 (+https://github.com/daezerwin/home-inventory)')
                ->withOptions([
                    // Redirects could point at an internal address, so they are not followed.
                    'allow_redirects' => false,
                    'sink' => $temporary,
                    // Pin the connection to the address that was checked above.
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    'progress' => static function ($size, $downloaded) {
                        if ($size > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new RuntimeException('Image exceeds 8 MB.');
                        }
                    },
                ])
                ->get($url);

            $response->throw();

            if ($response->status() !== 200) {
                throw new RuntimeException("Image server answered with HTTP {$response->status()}.");
            }

            [$contents, $extension] = $this->prepare($temporary);
            $path = 'products/'.hash('sha256', $url).'.'.$extension;
            Storage::disk('public')->put($path, $contents);

            // Ignore a download that finished after the catalog URL changed.
            Product::whereKey($product->id)->where('image_url', $url)->update(['image_path' => $path]);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port and the public IP it resolves to
     */
    private function resolveSafely(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $allowed = config('inventory.image_hosts');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Image URL must be a plain http(s) address.');
        }

        if ($allowed && ! in_array($host, $allowed, true)) {
            throw new RuntimeException("Image host {$host} is not in INVENTORY_IMAGE_HOSTS.");
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Image URL must use the standard web ports.');
        }

        $ip = gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Image host must resolve to a public address.');
        }

        return [$host, $port, $ip];
    }

    /**
     * @return array{0: string, 1: string} file contents and extension
     */
    private function prepare(string $file): array
    {
        $extension = match (@getimagesize($file)['mime'] ?? null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new RuntimeException('Downloaded file is not a supported image.'),
        };

        $maxSize = config('inventory.image_max_size');

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
