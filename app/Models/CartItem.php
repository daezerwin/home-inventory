<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = ['user_id', 'product_id', 'quantity', 'checked'];

    protected function casts(): array
    {
        return [
            'checked' => 'boolean',
            'quantity' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Number of products still to pick up on a user's shopping list. */
    public static function openCountFor(int $userId): int
    {
        return static::where('user_id', $userId)->where('checked', false)->count();
    }
}
