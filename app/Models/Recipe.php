<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Recipe extends Model
{
    protected $fillable = [
        'chef_id', 'user_id', 'title', 'description', 'source_url', 'image_url', 'image_path',
        'servings', 'prep_minutes', 'cook_minutes', 'total_minutes', 'instructions', 'draft',
    ];

    protected function casts(): array
    {
        return [
            'instructions' => 'array',
            'draft' => 'boolean',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'total_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Imports being reviewed in the wizard show up nowhere else; see drafts().
        static::addGlobalScope('published', fn (Builder $query) => $query->where('recipes.draft', false));

        static::creating(function (Recipe $recipe) {
            $recipe->slug ??= static::uniqueSlug($recipe->title);
        });
    }

    public function chef()
    {
        return $this->belongsTo(Chef::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    public function scopeDrafts(Builder $query): void
    {
        $query->withoutGlobalScope('published')->where('recipes.draft', true);
    }

    /** Deletes the recipe, and its photo unless another recipe (or draft) uses the same one. */
    public function deleteWithPhoto(): void
    {
        $shared = static::withoutGlobalScope('published')->where('image_path', $this->image_path)->whereKeyNot($this->id)->exists();

        if ($this->image_path && ! $shared) {
            Storage::disk('public')->delete($this->image_path);
        }

        $this->delete();
    }

    public function getImageAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }

    public function sourceDomain(): ?string
    {
        return $this->source_url ? preg_replace('/^www\./', '', parse_url($this->source_url, PHP_URL_HOST) ?? '') : null;
    }

    public function totalTime(): ?int
    {
        return $this->total_minutes ?: (($this->prep_minutes + $this->cook_minutes) ?: null);
    }

    public static function formatMinutes(?int $minutes): ?string
    {
        if (! $minutes) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours ? "{$hours} hr " : '').($rest ? "{$rest} min" : ''));
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($title), 80, '') ?: 'recipe';
        $slug = $base;

        for ($i = 2; static::withoutGlobalScope('published')->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
