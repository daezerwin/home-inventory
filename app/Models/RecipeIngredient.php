<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $fillable = ['recipe_id', 'position', 'line', 'name', 'product_id', 'skip'];

    protected function casts(): array
    {
        return ['skip' => 'boolean'];
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
