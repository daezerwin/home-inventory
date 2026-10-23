@extends('layouts.app')
@section('title', 'Pick ingredients')

@section('content')
@php($ingredients = $recipe->ingredients)
@php($skipped = $ingredients->where('skip', true)->count())
@php($picked = $ingredients->filter(fn ($ingredient) => ! $ingredient->skip && $ingredient->product_id)->count())
@php($open = $ingredients->count() - $picked - $skipped)

@include('partials.import-steps', ['step' => 2])

<div class="page-heading">
    <div>
        <div class="eyebrow">STEP 2 OF 3</div>
        <h1>Pick the ingredients.</h1>
        <p>Choose what you buy for each one. Tap a suggestion, search the catalog, add a new item, or skip what you always have.</p>
    </div>
</div>

<div class="review-layout">
    <section class="review-list" aria-label="Ingredients">
        @forelse ($ingredients as $ingredient)
            @php($product = $ingredient->product)
            @php($options = $suggestions[$ingredient->id]->when($product, fn ($items) => $items->reject(fn ($item) => $item->id === $product->id)->prepend($product))->take(4))
            @php($state = $ingredient->skip ? 'skipped' : ($product ? 'picked' : 'open'))
            <article class="review-row state-{{ $state }}" id="ingredient-{{ $ingredient->id }}">
                <header class="review-row-head">
                    <span class="review-number">{{ $loop->iteration }}</span>
                    <div>
                        <p class="ingredient-line">{{ $ingredient->line }}</p>
                        <small class="review-state">
                            @if ($state === 'skipped')
                                Skipped. You won't be asked to buy this.
                            @elseif ($state === 'picked')
                                <x-icon name="check" />Buying {{ $product->name }}
                            @else
                                Not picked yet. Pick an item, add a new one, or skip it.
                            @endif
                        </small>
                    </div>
                </header>

                @if ($options->isNotEmpty() && ! $ingredient->skip)
                    <form class="review-options" method="post" action="{{ route('recipe-ingredients.update', $ingredient) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="product">
                        @foreach ($options as $option)
                            @php($chosen = $product?->id === $option->id)
                            <button class="option-card {{ $chosen ? 'selected' : '' }}" name="product_id" value="{{ $option->id }}" aria-pressed="{{ $chosen ? 'true' : 'false' }}">
                                <span class="thumb">
                                    @if ($option->image)
                                        <img src="{{ $option->image }}" alt="" loading="lazy" decoding="async">
                                    @else
                                        <x-icon name="box" />
                                    @endif
                                </span>
                                <span class="option-text">
                                    <b>{{ $option->name }}</b>
                                    <small>{{ collect([$option->size, $option->price > 0 ? '₱'.number_format($option->price, 2) : null])->filter()->implode(' · ') ?: $option->category }}</small>
                                </span>
                                @if ($chosen)
                                    <x-icon name="check" class="option-check" />
                                @endif
                            </button>
                        @endforeach
                    </form>
                @endif

                <div class="review-actions">
                    @unless ($ingredient->skip)
                        <button type="button" class="button secondary compact" data-picker
                                data-picker-url="{{ route('recipe-ingredients.update', $ingredient) }}"
                                data-new-item-url="{{ route('recipes.new-item', [$recipe, $ingredient]) }}"
                                data-name="{{ $ingredient->name }}"
                                data-skipped="0"><x-icon name="search" />Search the catalog</button>
                        <a class="button secondary compact" href="{{ route('recipes.new-item', [$recipe, $ingredient]) }}"><x-icon name="plus" />Add a new item</a>
                    @endunless
                    <form method="post" action="{{ route('recipe-ingredients.update', $ingredient) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="{{ $ingredient->skip ? 'unskip' : 'skip' }}">
                        <button class="text-link">{{ $ingredient->skip ? "Don't skip. I need to buy this." : 'Skip. I always have this.' }}</button>
                    </form>
                </div>
            </article>
        @empty
            <div class="empty-state">
                <span class="stat-icon rose"><x-icon name="book" /></span>
                <h2>No ingredients found.</h2>
                <p>The page didn't list any. Save the recipe and add them with Edit.</p>
            </div>
        @endforelse
    </section>

    <aside class="review-summary">
        <div class="review-recipe">
            <span class="review-photo">
                @if ($recipe->image)
                    <img src="{{ $recipe->image }}" alt="" decoding="async">
                @else
                    <x-icon name="book" />
                @endif
            </span>
            <div>
                <b>{{ $recipe->title }}</b>
                <small>{{ collect([$recipe->chef?->name, $recipe->sourceDomain()])->filter()->implode(' · ') }}</small>
            </div>
        </div>

        <div class="review-progress" role="status">
            <div class="shopping-progress"><span style="width: {{ $ingredients->count() ? round(100 * ($picked + $skipped) / $ingredients->count()) : 100 }}%"></span></div>
            <p><b>{{ $picked + $skipped }} of {{ $ingredients->count() }}</b> ingredients decided</p>
            <ul>
                <li><span class="status-dot picked"></span>{{ $picked }} picked</li>
                <li><span class="status-dot skipped"></span>{{ $skipped }} skipped</li>
                @if ($open)
                    <li><span class="status-dot open"></span>{{ $open }} not picked yet</li>
                @endif
            </ul>
            @if ($open)
                <p class="muted">Anything not picked is added to the catalog as a new item when you stock the recipe.</p>
            @endif
        </div>

        <form class="review-finish" method="post" action="{{ route('recipes.finish', $recipe) }}">
            @csrf
            <button class="button primary" name="stock" value="1"><x-icon name="cart" />Save and stock ingredients</button>
            <button class="button secondary">Save recipe</button>
            <p class="muted">Stocking adds the ingredients to My pantry and puts the ones you have none of on your shopping list.</p>
        </form>

        <form method="post" action="{{ route('recipes.discard', $recipe) }}" data-confirm="Discard this import? The recipe won't be saved.">
            @csrf
            @method('DELETE')
            <button class="text-danger">Discard import</button>
        </form>
    </aside>
</div>

@include('partials.ingredient-picker', ['newItemPage' => true])
@endsection
