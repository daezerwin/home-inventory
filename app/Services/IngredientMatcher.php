<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns recipe lines ("5 cloves garlic, chopped") into the item they name
 * ("garlic") and finds the catalog product it is most likely bought as.
 *
 * Matching is word based: the ingredient's main noun (its last word) must be
 * in the product name, and the product's own main noun must be part of the
 * ingredient, so "eggplant" finds "SS Eggplant" but not "Eggplant Sauce", and
 * "egg" never finds "Eggplant". Groups where groceries live score higher.
 */
class IngredientMatcher
{
    /** Catalog group to the store's own name for things recipe ingredients become. */
    public const NEW_PRODUCT_GROUP = 'Recipe Ingredients';

    private const GROUP_WEIGHTS = [
        self::NEW_PRODUCT_GROUP => 4,
        'Fresh Produce' => 3,
        'Fresh Meat & Seafood' => 3,
        'Pantry' => 2,
        'Chilled & Dairy Items' => 1.5,
        'Frozen Goods' => 1,
        'Ready to Cook' => 1,
        'Bakery' => 0.5,
        'Beverage' => -3,
        'Snacks' => -3,
    ];

    private const NON_FOOD_GROUPS = [
        'Babies & Kids', 'Health & Beauty', 'Home Care', 'Pet Care',
        'Home Appliances & Essentials', 'DIY & Hardware', 'Health & Hygiene Essentials',
    ];

    private const UNITS = 'cups?|c|tablespoons?|tbsps?|tbs|tbl|teaspoons?|tsps?|pounds?|lbs?|ounces?|oz|grams?|g|gms?|kilograms?|kilos?|kg|milliliters?|millilitres?|ml|liters?|litres?|l|pieces?|pcs?|cloves?|bunch(?:es)?|heads?|cans?|tins?|packs?|packets?|sachets?|slices?|thumbs?|pinch(?:es)?|dash(?:es)?|sprigs?|stalks?|stems?|knobs?|inch(?:es)?|cm|bottles?|cubes?|blocks?|bars?|handfuls?|sticks?|jars?|bags?|boxes?|quarts?|pints?|gallons?|dozen';

    /** Words that describe preparation or size rather than what to buy. */
    private const DESCRIPTORS = [
        'chopped', 'minced', 'sliced', 'diced', 'crushed', 'grated', 'peeled', 'julienned', 'cubed', 'shredded',
        'beaten', 'softened', 'melted', 'cooked', 'uncooked', 'finely', 'thinly', 'roughly', 'coarsely', 'cracked',
        'freshly', 'fresh', 'large', 'medium', 'small', 'big', 'size', 'sized', 'ripe', 'whole', 'halved', 'quartered',
        'trimmed', 'rinsed', 'drained', 'washed', 'deveined', 'boneless', 'skinless', 'room', 'temperature', 'cold',
        'warm', 'hot', 'lukewarm', 'optional', 'taste', 'needed', 'garnish', 'serving', 'divided', 'about', 'plus',
        'extra', 'more', 'little', 'some', 'few', 'and', 'or', 'of', 'a', 'an', 'the', 'to', 'for', 'with', 'into',
        'cut', 'piece', 'pieces', 'per', 'up', 'approx', 'pack', 'pc', 'pcs', 'kg', 'g', 'gm', 'ml', 'l', 'lb',
    ];

    /** Filipino and English names for the same groceries, both ways where it helps. */
    private const SYNONYMS = [
        'talong' => 'eggplant',
        'bawang' => 'garlic',
        'sibuyas' => 'onion',
        'kamatis' => 'tomato',
        'luya' => 'ginger',
        'toyo' => 'soy sauce',
        'suka' => 'vinegar',
        'patis' => 'fish sauce',
        'bay leaf' => 'laurel leaf',
        'laurel' => 'laurel leaf',
        'green onion' => 'onion leek',
        'spring onion' => 'onion leek',
        'scallion' => 'onion leek',
        'string bean' => 'sitaw',
        'sitaw' => 'string bean',
        'plain flour' => 'all purpose flour',
        'vegetable oil' => 'cooking oil',
        'pork belly' => 'pork liempo',
        'liempo' => 'pork belly',
        'ground pork' => 'pork giniling',
        'ground beef' => 'beef giniling',
        'coconut milk' => 'gata',
        'siling labuyo' => 'chili',
        'chilli' => 'chili',
    ];

    private const STAPLES = ['water', 'ice', 'hot water', 'warm water', 'cold water', 'boiling water', 'ice water'];

    /** The thing to buy, in a form fit for a catalog name ("Chinese eggplant"). */
    public function name(string $line, ?string $hint = null): string
    {
        $name = $hint ? Str::squish($hint) : $this->nameFromLine($line);

        return Str::ucfirst(Str::limit($name !== '' ? $name : Str::squish($line), 120, ''));
    }

    public function isStaple(string $name): bool
    {
        return in_array(implode(' ', $this->tokens($name, keepDescriptors: true)), self::STAPLES, true)
            || in_array(Str::lower(Str::squish($name)), self::STAPLES, true);
    }

    /**
     * The product an ingredient is most likely bought as. Products in $preferIds
     * (the person's pantry) win when one fits, so a recipe uses what is at home
     * instead of a look-alike from the catalog.
     *
     * @param  list<int>  $preferIds
     */
    public function match(string $name, array $preferIds = []): ?Product
    {
        return ($preferIds ? $this->rank($name, 1, minimum: 5, onlyIds: $preferIds)->first() : null)
            ?? $this->rank($name, 1, minimum: 5)->first();
    }

    /**
     * Catalog products for an ingredient or search term, best first; only
     * among $onlyIds when given.
     *
     * $loose is for a short list such as a pantry: sharing any word is enough
     * ("Minola Cooking Oil" for "extra virgin olive oil", "Turmeric Powder" for
     * "fresh turmeric root"), still ranked below closer matches.
     *
     * @param  list<int>|null  $onlyIds
     * @return Collection<int, Product>
     */
    public function rank(string $query, int $limit = 20, float $minimum = 0, ?array $onlyIds = null, bool $loose = false): Collection
    {
        $scored = collect();
        $loose = $loose && $onlyIds !== null;

        if ($onlyIds === []) {
            return $scored;
        }

        foreach ($this->variants($query) as $words) {
            $head = end($words);
            $root = mb_substr($head, 0, max(3, mb_strlen($head) - 1));

            $candidates = Product::query()
                ->when($onlyIds !== null, fn ($products) => $products->whereKey($onlyIds))
                ->whereNotIn('category', self::NON_FOOD_GROUPS)
                // Loosely, any word may match, so the whole (short) list is scored.
                ->when(! $loose, fn ($products) => $products->where('name', 'like', '%'.addcslashes($root, '%_\\').'%'))
                ->orderByRaw('length(name)')
                ->limit(600)
                ->get();

            foreach ($candidates as $product) {
                $score = $this->score($words, $product, $loose);

                // $minimum is for strict matches; loose ones only need to share a word.
                if ($score !== null && ($loose || $score >= $minimum) && $score > ($scored[$product->id]['score'] ?? -INF)) {
                    $scored[$product->id] = ['product' => $product, 'score' => $score];
                }
            }
        }

        return $scored
            ->sortBy([['score', 'desc'], [fn ($a, $b) => $a['product']->price <=> $b['product']->price]])
            ->take($limit)
            ->pluck('product')
            ->values();
    }

    private function score(array $words, Product $product, bool $loose = false): ?float
    {
        $productWords = $this->tokens($product->name);
        $matched = count(array_intersect($words, $productWords));
        $headMatch = in_array(end($words), $productWords, true);

        if (! $matched || (! $headMatch && ! $loose)) {
            return null;
        }

        $coverage = $matched / count($words);

        if ($coverage < 0.5 && ! $loose) {
            return null;
        }

        $extra = count(array_diff($productWords, $words));
        $typeWords = $this->tokens((string) $product->type);

        return 10 * $coverage
            - 0.6 * $extra
            + (self::GROUP_WEIGHTS[$product->category] ?? 0)
            + (array_intersect($words, $typeWords) ? 1.5 : 0)
            // "Eggplant Sauce" is a sauce: penalise products whose main noun isn't in the ingredient.
            - (in_array(end($productWords), $words, true) ? 0 : 3)
            // A loose match that misses the ingredient's own main noun ranks last.
            - ($headMatch ? 0 : 4);
    }

    /** @return list<list<string>> the ingredient's words, plus synonym spellings */
    private function variants(string $query): array
    {
        $words = $this->tokens($query);

        if (! $words) {
            return [];
        }

        $variants = [$words];
        $phrase = ' '.implode(' ', $words).' ';

        foreach (self::SYNONYMS as $from => $to) {
            if (str_contains($phrase, " {$from} ")) {
                $variants[] = $this->tokens(trim(str_replace(" {$from} ", " {$to} ", $phrase)));
            }
        }

        return array_values(array_filter($variants));
    }

    /** @return list<string> lower-case, singular words without descriptors */
    private function tokens(string $text, bool $keepDescriptors = false): array
    {
        $text = Str::of($text)->lower()->ascii()->replace(['&', '-', '/'], ' ')->replaceMatches('/[^a-z ]+/', ' ')->squish();

        return collect(explode(' ', (string) $text))
            ->filter(fn ($word) => mb_strlen($word) > 1)
            ->map(fn ($word) => $this->singular($word))
            ->when(! $keepDescriptors, fn ($words) => $words->reject(fn ($word) => in_array($word, self::DESCRIPTORS, true)))
            ->values()
            ->all();
    }

    private function singular(string $word): string
    {
        return match (true) {
            in_array($word, ['leaves', 'halves', 'loaves'], true) => substr($word, 0, -3).'f',
            str_ends_with($word, 'ies') && strlen($word) > 4 => substr($word, 0, -3).'y',
            str_ends_with($word, 'oes') => substr($word, 0, -2),
            (bool) preg_match('/(ch|sh|ss|x)es$/', $word) => substr($word, 0, -2),
            str_ends_with($word, 's') && ! preg_match('/(ss|us|is)$/', $word) && strlen($word) > 3 => substr($word, 0, -1),
            default => $word,
        };
    }

    private function nameFromLine(string $line): string
    {
        $text = Str::lower(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\([^)]*\)|\[[^]]*\]/', ' ', $text);   // "(medium size)"
        $text = preg_split('/[,;:]| - | – /u', $text)[0];            // ", chopped"
        $text = preg_replace('/\b(to taste|as needed|optional|if desired)\b.*$|\s(plus|for)\s.*$/', '', $text);

        $units = self::UNITS;
        $amount = '(?:[\d½⅓⅔¼¾⅛⅜⅝⅞]+(?:[.,\/][\d]+)?(?:\s*(?:-|–|to)\s*[\d½⅓⅔¼¾⅛⅜⅝⅞]+(?:[.,\/][\d]+)?)?\s*)';

        // Leading amounts and units, possibly repeated ("1 can (400 g) …", "100g …").
        for ($i = 0; $i < 3; $i++) {
            $text = preg_replace("/^\s*(?:{$amount}+|a |an |one |two |three |few |some )/u", ' ', $text);
            $text = preg_replace("/^\s*(?:{$units})\.?\s+(?:of\s+)?/u", ' ', $text);
        }

        // "sunflower or vegetable oil" shares its noun; "butter or margarine" doesn't.
        $alternatives = preg_split('/\s+or\s+/', trim($text));

        if (count($alternatives) > 1) {
            $first = trim($alternatives[0]);
            $last = trim(end($alternatives));
            $text = ! str_contains($first, ' ') && str_contains($last, ' ') ? $last : $first;
        }

        $words = array_filter(
            explode(' ', Str::squish($text)),
            fn ($word) => ! in_array($word, ['chopped', 'minced', 'sliced', 'diced', 'crushed', 'grated', 'peeled', 'julienned', 'cubed', 'beaten', 'softened', 'melted', 'finely', 'thinly', 'roughly', 'coarsely', 'plus', 'extra', 'large', 'medium', 'small'], true),
        );

        // "garlic crushed and chopped" leaves a dangling "and"; "salt and pepper" keeps its own.
        return Str::of(implode(' ', $words))->squish()->replaceMatches('/^(and\s+)+|(\s+and)+$/', '')->replaceMatches('/\band(\s+and)+\b/', 'and')->toString();
    }
}
