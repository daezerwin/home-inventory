<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Chef extends Model
{
    protected $fillable = ['name', 'site_name', 'website', 'bio', 'hero_recipe_id'];

    protected static function booted(): void
    {
        static::creating(function (Chef $chef) {
            $chef->slug ??= static::uniqueSlug($chef->name);
        });
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function heroRecipe()
    {
        return $this->belongsTo(Recipe::class, 'hero_recipe_id');
    }

    /** Photo for the page hero: the chosen recipe, else the newest recipe with a photo. */
    public function heroImage(): ?string
    {
        $recipe = $this->heroRecipe?->image_path
            ? $this->heroRecipe
            : $this->recipes()->whereNotNull('image_path')->latest('id')->first();

        return $recipe?->image;
    }

    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');
    }

    public function domain(): ?string
    {
        return $this->website ? preg_replace('/^www\./', '', parse_url($this->website, PHP_URL_HOST) ?? '') : null;
    }

    /** Finds a chef by name (case-insensitive) or creates one. */
    public static function findOrCreateByName(string $name, array $attributes = []): self
    {
        $name = Str::squish($name);

        return static::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create(['name' => $name, ...$attributes]);
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'chef';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
