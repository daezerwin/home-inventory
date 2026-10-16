@php($stock = $product->stocks->first())
@php($inCart = $product->cartItems->first())
@php($status = ! $stock ? null : ($stock->quantity === 0 ? 'out' : ($stock->quantity < $stock->target_quantity ? 'low' : 'ok')))

<article class="product-card" @if ($stock) data-stock-card data-target="{{ $stock->target_quantity }}" @endif>
    <div class="product-photo">
        @if ($product->image)
            <img src="{{ $product->image }}" alt="{{ $product->name }}" loading="lazy" decoding="async" onerror="this.hidden = true; this.nextElementSibling.hidden = false">
        @endif
        <div class="photo-placeholder" @if ($product->image) hidden @endif>
            <x-icon name="box" /><span>{{ $product->type ?: $product->category }}</span>
        </div>
        @if ($stock)
            <span class="stock-tag {{ $status !== 'ok' ? 'low' : '' }}" data-stock-tag>
                <span></span><em>{{ ['out' => 'Out of stock', 'low' => 'Low stock', 'ok' => 'In your pantry'][$status] }}</em>
            </span>
        @endif
    </div>

    <div class="product-info">
        <span class="product-category">{{ $product->category }}</span>
        <h3 title="{{ $product->name }}">{{ $product->name }}</h3>
        <p class="product-size">{{ $product->size ?: 'Standard size' }} <span>·</span> {{ $product->type ?: 'Everyday essential' }}</p>
        <div class="price-row"><strong>₱{{ number_format($product->price, 2) }}</strong><span>catalog price</span></div>

        @if ($stock)
            <form class="stock-stepper" method="post" action="{{ route('pantry.adjust', $product) }}" data-async="stock-adjust">
                @csrf
                <span class="stock-label">At home</span>
                <button class="step" name="delta" value="-1" aria-label="Used one {{ $product->name }}">−</button>
                <b data-stock-qty>{{ $stock->quantity }}</b>
                <button class="step" name="delta" value="1" aria-label="Add one {{ $product->name }}">+</button>
                <span class="stock-target">of {{ $stock->target_quantity }}</span>
            </form>
            <div class="stock-meter"><span data-stock-meter style="width: {{ min(100, 100 * $stock->quantity / max(1, $stock->target_quantity)) }}%"></span></div>
        @endif

        <div class="card-actions">
            @if ($stock)
                <button type="button" class="button track" data-dialog="stock-{{ $product->id }}"><x-icon name="settings" />Manage</button>
            @else
                <form method="post" action="{{ route('pantry.track', $product) }}">
                    @csrf
                    <button class="button track"><x-icon name="plus" />My pantry</button>
                </form>
            @endif
            <form method="post" action="{{ route('cart.add', $product) }}" data-async="cart-add">
                @csrf
                <button class="button cart-button {{ $inCart ? 'in-cart' : '' }}" aria-label="Add {{ $product->name }} to shopping list">
                    <x-icon name="cart" /><small data-cart-qty>{{ $inCart ? $inCart->quantity : '+' }}</small>
                </button>
            </form>
        </div>
    </div>
</article>

@if ($stock)
    <dialog id="stock-{{ $product->id }}" aria-labelledby="stock-{{ $product->id }}-title">
        <div class="dialog-heading">
            <h2 id="stock-{{ $product->id }}-title">Home stock</h2>
            <button type="button" class="icon-button" data-close-dialog aria-label="Close">×</button>
        </div>
        <p>{{ $product->name }} · {{ $product->size }}</p>
        <form method="post" action="{{ route('pantry.save', $product) }}" class="stack-form">
            @csrf
            @method('PUT')
            <div class="form-columns">
                <label>Have at home<input type="number" inputmode="numeric" name="quantity" min="0" max="1000000" value="{{ $stock->quantity }}" required></label>
                <label>Keep at least<input type="number" inputmode="numeric" name="target_quantity" min="1" max="1000000" value="{{ $stock->target_quantity }}" required></label>
            </div>
            <label>Where it's kept<input name="location" value="{{ $stock->location }}" maxlength="120" placeholder="e.g. Kitchen shelf"></label>
            <p class="muted">When you have fewer than "keep at least", the item counts as low and <b>Restock low items</b> adds the difference to your shopping list.</p>
            <button class="button primary">Save home stock</button>
        </form>
        <div class="dialog-bottom">
            <form method="post" action="{{ route('pantry.remove', $product) }}" data-confirm="Remove {{ $product->name }} from your pantry? It stays on your shopping list if it's there.">
                @csrf
                @method('DELETE')
                <button class="text-danger">Remove from pantry</button>
            </form>
        </div>
    </dialog>
@endif
