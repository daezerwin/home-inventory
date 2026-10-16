<?php

namespace App\Services;

use App\Jobs\DownloadProductImage;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Imports the inventory CSV (sku, group, name, image, type, size, price).
 *
 * The whole file is validated before anything is written, then products are
 * upserted by SKU in one transaction. Pantry stock and shopping lists point at
 * products by id, so they survive re-imports untouched.
 */
class CsvImporter
{
    public const COLUMNS = ['sku', 'group', 'name', 'image', 'type', 'size', 'price'];

    private const MAX_ROWS = 50000;

    private const RULES = [
        'sku' => 'required|string|max:255',
        'group' => 'required|string|max:255',
        'name' => 'required|string|max:255',
        'type' => 'nullable|string|max:255',
        'size' => 'nullable|string|max:255',
        'image' => 'nullable|url:http,https|max:2048',
        'price' => 'required|numeric|min:0|max:9999999999.99',
    ];

    /** @return array{imported: int} */
    public function import(string $path, bool $images = true): array
    {
        $rows = $this->read($path);

        $count = DB::transaction(function () use ($rows, $images) {
            foreach ($rows as $row) {
                $product = Product::firstOrNew(['sku' => $row['sku']]);
                $imageUrl = $row['image'] ?: null;

                // A new photo URL means the stored copy is stale.
                if ($product->image_url !== $imageUrl) {
                    $product->image_path = null;
                }

                $product->fill([
                    'category' => $row['group'],
                    'name' => $row['name'],
                    'image_url' => $imageUrl,
                    'type' => $row['type'] ?: null,
                    'size' => $row['size'] ?: null,
                    'price' => $row['price'],
                ])->save();

                if ($images && $product->image_url && ! $product->image_path) {
                    DownloadProductImage::dispatch($product->id)->afterCommit();
                }
            }

            return count($rows);
        });

        return ['imported' => $count];
    }

    /** @return list<array<string, string>> */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');

            if (! $headers) {
                $this->fail('The CSV is empty.');
            }

            $headers = array_map(fn ($header) => strtolower(trim(ltrim($header, "\xEF\xBB\xBF"))), $headers);

            foreach (self::COLUMNS as $column) {
                if (! in_array($column, $headers, true)) {
                    $this->fail("Missing column: {$column}. Expected ".implode(',', self::COLUMNS).'.');
                }
            }

            $rows = [];
            $seen = [];
            $line = 1;

            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;

                if ($values === [null]) {
                    continue; // blank line
                }

                if (count($values) !== count($headers)) {
                    $this->fail("Row {$line}: column count does not match the header.");
                }

                $row = array_map(fn ($value) => trim($value ?? ''), array_combine($headers, $values));

                // Some catalogs use literal markers instead of leaving the image empty.
                if (in_array(strtolower($row['image']), ['none', 'null', 'nan', 'n/a', '-'], true)) {
                    $row['image'] = '';
                }

                $validator = Validator::make($row, self::RULES);

                if ($validator->fails()) {
                    $this->fail("Row {$line}: ".$validator->errors()->first());
                }

                if (isset($seen[$row['sku']])) {
                    $this->fail("Row {$line}: duplicate SKU {$row['sku']}.");
                }

                $seen[$row['sku']] = true;
                $rows[] = $row;

                if (count($rows) > self::MAX_ROWS) {
                    $this->fail('Import a maximum of '.number_format(self::MAX_ROWS).' rows at a time.');
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['csv' => $message]);
    }
}
