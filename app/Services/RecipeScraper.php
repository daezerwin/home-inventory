<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads a recipe from a web page. Nearly every recipe site publishes the
 * recipe as schema.org JSON-LD for search engines (WP Recipe Maker, Tasty
 * Recipes, BBC Good Food, …), so that is what gets parsed; the visible page
 * layout is ignored.
 */
class RecipeScraper
{
    private const MAX_BYTES = 6 * 1024 * 1024;

    public function __construct(private SafeHttp $http) {}

    /**
     * @return array{
     *     title: string, description: ?string, image_url: ?string, servings: ?string,
     *     prep_minutes: ?int, cook_minutes: ?int, total_minutes: ?int,
     *     ingredients: list<array{line: string, name: ?string}>,
     *     instructions: list<array{type: string, text: string}>,
     *     author: ?string, site_name: ?string, website: string, source_url: string
     * }
     */
    public function fetch(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        try {
            [$response, $finalUrl] = $this->http->get($url, self::MAX_BYTES, headers: ['Accept' => 'text/html,application/xhtml+xml']);
        } catch (ConnectionException) {
            throw new RecipeImportException("Couldn't connect to {$host}. Check the link and try again.");
        } catch (RuntimeException $e) {
            throw new RecipeImportException($e->getMessage());
        }

        if (in_array($response->status(), [401, 402, 403, 429, 503], true)) {
            throw new RecipeImportException("{$host} refused the download (HTTP {$response->status()}). Some sites block apps like this one.");
        }

        if (! $response->successful()) {
            throw new RecipeImportException("{$host} answered with HTTP {$response->status()}. Check the link and try again.");
        }

        return $this->parse($response->body(), $finalUrl);
    }

    public function parse(string $html, string $url): array
    {
        $xpath = $this->xpath($html);
        $nodes = $this->jsonLdNodes($xpath);
        $recipe = collect($nodes)->first(fn ($node) => $this->isType($node, 'Recipe'));

        if (! $recipe) {
            throw new RecipeImportException("No recipe data was found on that page. It may not be a recipe page, or the site doesn't publish its recipes in a standard format.");
        }

        // Index the fullest node for each @id; a bare {"@id": …} is only a reference.
        $byId = [];

        foreach ($nodes as $node) {
            $id = $node['@id'] ?? null;

            if (is_string($id) && count($node) > 1 && count($node) > count($byId[$id] ?? [])) {
                $byId[$id] = $node;
            }
        }

        $resolve = fn ($value) => is_array($value) && isset($value['@id']) && count($value) === 1 ? ($byId[$value['@id']] ?? $value) : $value;

        $lines = collect($this->list($recipe['recipeIngredient'] ?? $recipe['ingredients'] ?? []))
            ->map(fn ($line) => $this->text(is_array($line) ? ($line['text'] ?? $line['name'] ?? '') : $line))
            ->filter()
            ->values();

        // WP Recipe Maker also marks up just the ingredient name ("garlic"), which beats guessing.
        $names = $this->wprmNames($xpath);
        $ingredients = $lines->map(fn ($line, $i) => [
            'line' => Str::limit($line, 480),
            'name' => count($names) === $lines->count() ? $names[$i] : null,
        ])->all();

        $origin = $this->origin($url);
        $title = $this->text($recipe['name'] ?? '') ?: $this->metaContent($xpath, 'og:title') ?: 'Untitled recipe';

        return [
            'title' => Str::limit($title, 250),
            'description' => $this->text($recipe['description'] ?? '') ?: null,
            'image_url' => $this->imageUrl($resolve($recipe['image'] ?? null), $url) ?? $this->metaContent($xpath, 'og:image'),
            'servings' => $this->servings($recipe['recipeYield'] ?? null),
            'prep_minutes' => $this->minutes($recipe['prepTime'] ?? null),
            'cook_minutes' => $this->minutes($recipe['cookTime'] ?? null),
            'total_minutes' => $this->minutes($recipe['totalTime'] ?? null),
            'ingredients' => $ingredients,
            'instructions' => $this->instructions($recipe['recipeInstructions'] ?? []),
            'author' => $this->names($resolve($recipe['author'] ?? null), $resolve),
            'site_name' => $this->metaContent($xpath, 'og:site_name')
                ?: $this->names($resolve($recipe['publisher'] ?? null), $resolve)
                ?: preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST)),
            'website' => $origin,
            'source_url' => $this->canonical($xpath, $url) ?? $url,
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** Every JSON-LD object on the page, with @graph containers and nesting flattened. */
    private function jsonLdNodes(DOMXPath $xpath): array
    {
        $nodes = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $json = trim($script->textContent);
            $data = json_decode($json, true) ?? json_decode(preg_replace('/[\x00-\x1F]+/', ' ', $json), true);

            if (is_array($data)) {
                $this->collect($data, $nodes);
            }
        }

        return $nodes;
    }

    private function collect(array $data, array &$nodes): void
    {
        if (isset($data['@type']) || isset($data['@id'])) {
            $nodes[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collect($value, $nodes);
            }
        }
    }

    private function isType(array $node, string $type): bool
    {
        return in_array($type, (array) ($node['@type'] ?? []), true);
    }

    private function list(mixed $value): array
    {
        return $value === null ? [] : (is_array($value) && array_is_list($value) ? $value : [$value]);
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::squish($text);
    }

    /** @return list<array{type: string, text: string}> */
    private function instructions(mixed $value): array
    {
        $steps = [];

        foreach ($this->list($value) as $item) {
            if (is_string($item)) {
                // A single text block: one step per line.
                foreach (preg_split('/\R+/', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $item)))) as $line) {
                    if ($text = $this->text(preg_replace('/^\s*\d+[.)]\s*/', '', $line))) {
                        $steps[] = ['type' => 'step', 'text' => $text];
                    }
                }
            } elseif (is_array($item) && ($this->isType($item, 'HowToSection') || isset($item['itemListElement']))) {
                if ($name = $this->text($item['name'] ?? '')) {
                    $steps[] = ['type' => 'section', 'text' => $name];
                }

                array_push($steps, ...$this->instructions($item['itemListElement'] ?? []));
            } elseif (is_array($item)) {
                if ($text = $this->text($item['text'] ?? $item['name'] ?? '')) {
                    $steps[] = ['type' => 'step', 'text' => $text];
                }
            }
        }

        return $steps;
    }

    private function imageUrl(mixed $image, string $pageUrl): ?string
    {
        foreach ($this->list($image) as $candidate) {
            $url = is_array($candidate) ? ($candidate['url'] ?? $candidate['contentUrl'] ?? null) : $candidate;

            if (is_string($url) && $url !== '') {
                return $this->http->absolute($url, $pageUrl);
            }
        }

        return null;
    }

    private function servings(mixed $yield): ?string
    {
        $values = collect($this->list($yield))->map(fn ($value) => $this->text($value))->filter();
        $value = $values->first(fn ($value) => ! is_numeric($value)) ?? $values->first();

        if ($value === null) {
            return null;
        }

        return Str::limit(is_numeric($value) ? "{$value} servings" : $value, 120);
    }

    /** ISO 8601 durations ("PT1H30M", "P0DT45M") to minutes. */
    private function minutes(mixed $duration): ?int
    {
        if (! is_string($duration) || ! preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:[\d.]+S)?)?$/i', trim($duration), $m)) {
            return null;
        }

        $minutes = ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);

        return $minutes > 0 ? min($minutes, 65000) : null;
    }

    /** The name(s) of a Person/Organization value, which may be a string, an object or a list. */
    private function names(mixed $value, callable $resolve): ?string
    {
        $names = collect($this->list($value))
            ->map(fn ($item) => is_array($item) ? $this->text($resolve($item)['name'] ?? '') : $this->text($item))
            ->filter()
            ->unique();

        return $names->isEmpty() ? null : Str::limit($names->implode(' & '), 120, '');
    }

    /** @return list<string> */
    private function wprmNames(DOMXPath $xpath): array
    {
        $names = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " wprm-recipe-ingredient-name ")]') as $node) {
            $names[] = $this->text($node->textContent);
        }

        return $names;
    }

    private function metaContent(DOMXPath $xpath, string $property): ?string
    {
        $node = $xpath->query("//meta[@property=\"{$property}\" or @name=\"{$property}\"]/@content")->item(0);

        return $node ? ($this->text($node->nodeValue) ?: null) : null;
    }

    private function canonical(DOMXPath $xpath, string $url): ?string
    {
        $href = $xpath->query('//link[@rel="canonical"]/@href')->item(0)?->nodeValue;

        if (! $href) {
            return null;
        }

        $absolute = $this->http->absolute(trim($href), $url);

        // Only trust a canonical URL on the same site.
        return parse_url($absolute, PHP_URL_HOST) === parse_url($url, PHP_URL_HOST) ? $absolute : null;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'];
    }
}
