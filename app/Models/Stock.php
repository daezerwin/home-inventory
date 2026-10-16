<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $fillable = ['user_id', 'product_id', 'quantity', 'target_quantity', 'location'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'target_quantity' => 'integer'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
