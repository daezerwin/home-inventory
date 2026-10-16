<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['sku', 'category', 'name', 'image_url', 'image_path', 'type', 'size', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function getImageAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
