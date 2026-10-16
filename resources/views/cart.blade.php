@extends('layouts.app')
@section('title', 'Shopping list')

@section('content')
@php($checked = $items->where('checked', true)->count())

<div class="page-heading">
    <div>
        <div class="eyebrow">A LITTLE LIST, A LIGHTER MIND</div>
        <h1>Your next grocery run.</h1>
        <p>Tick things off as you shop. Bring them home when you're done.</p>
    </div>
    <a class="button secondary" href="{{ route('catalog') }}"><x-icon name="plus" />Add more items</a>
</div>

<div class="shopping-layout" data-shopping-list>
    <section class="shopping-items">
        <div class="section-heading">
            <h2>Shopping list <span class="count-pill">{{ $items->count() }}</span></h2>
            <div class="list-tools">
                <span class="muted"><b data-checked-count>{{ $checked }}</b> of <span data-total-count>{{ $items->count() }}</span> in the cart</span>
                @if ($items->isNotEmpty())
                    <button type="button" class="button secondary compact" data-hide-checked aria-pressed="false">Hide checked</button>
                @endif
            </div>
        </div>
        <div class="shopping-progress"><span data-progress style="width: {{ $items->count() ? 100 * $checked / $items->count() : 0 }}%"></span></div>

        @forelse ($groups as $group => $groupItems)
            <h3 class="aisle">{{ $group }}</h3>
            @foreach ($groupItems as $item)
                <article class="shopping-row {{ $item->checked ? 'is-checked' : '' }}" data-row data-price="{{ $item->product->price }}">
                    <form method="post" action="{{ route('cart.update', $item) }}" data-async="cart-check">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="checked" value="{{ $item->checked ? 0 : 1 }}">
                        <button class="check-button" aria-label="In the cart: {{ $item->product->name }}" aria-pressed="{{ $item->checked ? 'true' : 'false' }}"><x-icon name="check" /></button>
                    </form>
                    <div class="shopping-photo">
                        @if ($item->product->image)
                            <img src="{{ $item->product->image }}" alt="" loading="lazy">
                        @else
                            <x-icon name="box" />
                        @endif
                    </div>
                    <div class="shopping-detail">
                        <h3>{{ $item->product->name }}</h3>
                        <p class="muted">{{ $item->product->size }} · ₱{{ number_format($item->product->price, 2) }} each</p>
                    </div>
                    <form class="qty-stepper" method="post" action="{{ route('cart.update', $item) }}" data-async="cart-qty">
                        @csrf
                        @method('PATCH')
                        <button class="step" name="quantity" value="{{ max(1, $item->quantity - 1) }}" data-step="-1" aria-label="One fewer" @disabled($item->quantity <= 1)>−</button>
                        <b data-qty>{{ $item->quantity }}</b>
                        <button class="step" name="quantity" value="{{ $item->quantity + 1 }}" data-step="1" aria-label="One more">+</button>
                    </form>
                    <strong class="line-total" data-line-total>₱{{ number_format($item->quantity * $item->product->price, 2) }}</strong>
                    <form class="remove-form" method="post" action="{{ route('cart.remove', $item) }}" data-async="cart-remove">
                        @csrf
                        @method('DELETE')
                        <button class="icon-button" aria-label="Remove {{ $item->product->name }}"><x-icon name="trash" /></button>
                    </form>
                </article>
            @endforeach
        @empty
            <div class="empty-state">
                <span class="stat-icon rose"><x-icon name="cart" /></span>
                <h2>Room for your next grocery run.</h2>
                <p>Add items from the catalog, or use <b>Restock low items</b> in My pantry.</p>
                <a class="button primary" href="{{ route('catalog') }}">Find your essentials</a>
            </div>
        @endforelse
    </section>

    <aside class="shopping-summary">
        <span class="eyebrow">BRING GOOD THINGS HOME</span>
        <h2>A little overview.</h2>
        <div><span>Products on your list</span><strong data-total-count>{{ $items->count() }}</strong></div>
        <div><span>Total units</span><strong data-units>{{ $items->sum('quantity') }}</strong></div>
        <div><span>In the cart</span><strong data-checked-count>{{ $checked }}</strong></div>
        <hr>
        <div class="summary-total"><span>Estimated total</span><strong data-grand-total>₱{{ number_format($items->sum(fn ($i) => $i->quantity * $i->product->price), 2) }}</strong></div>
        <p>Based on catalog prices. Your grocery receipt may vary.</p>
        <form method="post" action="{{ route('cart.checkout') }}">
            @csrf
            <button class="button primary" data-checkout @disabled(! $checked)><x-icon name="home" />Stock checked items</button>
        </form>
        <p class="summary-hint">Adds the checked quantities to your home stock, then clears them from this list. Unchecked items stay for next time.</p>
    </aside>
</div>
@endsection
