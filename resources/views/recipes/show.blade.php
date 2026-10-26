@extends('layouts.app')
@section('title', $recipe->title)

@php
    // Where each ingredient stands for the signed-in person.
    $rows = $recipe->ingredients->map(function ($ingredient) use ($stocks, $cart) {
        $stock = $ingredient->product_id ? $stocks->get($ingredient->product_id) : null;
        $listed = $ingredient->product_id && $cart->has($ingredient->product_id);

        $status = match (true) {
            $ingredient->skip => 'skipped',
            ! $ingredient->product_id => 'new',
            $stock && $stock->quantity > 0 => 'home',
            $listed => 'listed',
            (bool) $stock => 'out',
            default => 'missing',
        };

        return compact('ingredient', 'stock', 'status');
    });
    $count = fn (string ...$statuses) => $rows->whereIn('status', $statuses)->count();
    // "Use ingredients": the recipe's products that are in the pantry, one row each.
    $usable = $rows->filter(fn ($row) => $row['stock'] && ! $row['ingredient']->skip)
        ->groupBy(fn ($row) => $row['ingredient']->product_id)
        ->map(fn ($group) => ['stock' => $group->first()['stock'], 'product' => $group->first()['ingredient']->product, 'lines' => $group->pluck('ingredient.line')]);
    $notInPantry = $rows->filter(fn ($row) => ! $row['stock'] && ! $row['ingredient']->skip)->count();
    $toBuy = $count('new', 'out', 'missing');
    $steps = collect($recipe->instructions ?? []);
    $stepNumber = 0;
@endphp

@section('content')
<article class="recipe-page">
    <header class="recipe-hero">
        <div class="recipe-hero-photo">
            @if ($recipe->image)
                <img src="{{ $recipe->image }}" alt="{{ $recipe->title }}" decoding="async" fetchpriority="high">
            @else
                <div class="photo-placeholder"><x-icon name="book" /><span>Recipe</span></div>
            @endif
        </div>
        <div class="recipe-hero-copy">
            @if ($recipe->chef)
                <a class="eyebrow chef-link" href="{{ route('chefs.show', $recipe->chef) }}">
                    <x-icon name="chef" />{{ $recipe->chef->name }}@if ($recipe->chef->site_name && $recipe->chef->site_name !== $recipe->chef->name) · {{ $recipe->chef->site_name }}@endif
                </a>
            @endif
            <h1>{{ $recipe->title }}</h1>
            @if ($recipe->description)
                <p class="recipe-description">{{ $recipe->description }}</p>
            @endif

            <dl class="recipe-facts">
                @foreach (['Prep' => $recipe->prep_minutes, 'Cook' => $recipe->cook_minutes, 'Total' => $recipe->totalTime()] as $label => $minutes)
                    @if ($minutes)
                        <div><dt>{{ $label }}</dt><dd>{{ \App\Models\Recipe::formatMinutes($minutes) }}</dd></div>
                    @endif
                @endforeach
                @if ($recipe->servings)
                    <div><dt>Serves</dt><dd>{{ $recipe->servings }}</dd></div>
                @endif
            </dl>

            <div class="recipe-actions">
                <form method="post" action="{{ route('recipes.stock', $recipe) }}">
                    @csrf
                    <button class="button primary"><x-icon name="cart" />Stock ingredients</button>
                </form>
                <button type="button" class="button secondary" data-dialog="use-ingredients"><x-icon name="box" />Use ingredients</button>
                <a class="button secondary" href="{{ route('recipes.edit', $recipe) }}"><x-icon name="edit" />Edit</a>
                @if ($canDelete)
                    <form method="post" action="{{ route('recipes.destroy', $recipe) }}" data-confirm="Delete {{ $recipe->title }}? Pantry items and catalog products it added are kept." data-confirm-button="Delete recipe">
                        @csrf
                        @method('DELETE')
                        <button class="button secondary danger"><x-icon name="trash" />Delete</button>
                    </form>
                @endif
                @if ($recipe->source_url)
                    <a class="button secondary" href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer"><x-icon name="external" />{{ $recipe->sourceDomain() }}</a>
                @endif
            </div>
        </div>
    </header>

    <div class="recipe-body">
        <section class="ingredients-card" id="ingredients">
            <div class="section-heading">
                <h2>Ingredients <span class="count-pill">{{ $rows->count() }}</span></h2>
            </div>
            <p class="ingredient-summary">
                <span class="status-chip home">{{ $count('home') }} at home</span>
                <span class="status-chip buy">{{ $toBuy }} to buy</span>
                @if ($count('listed'))
                    <span class="status-chip listed">{{ $count('listed') }} on your list</span>
                @endif
                @if ($count('skipped'))
                    <span class="status-chip skipped">{{ $count('skipped') }} skipped</span>
                @endif
            </p>

            <ul class="ingredient-list">
                @foreach ($rows as ['ingredient' => $ingredient, 'stock' => $stock, 'status' => $status])
                    @php($product = $ingredient->product)
                    <li class="ingredient-row status-{{ $status }}">
                        <span class="status-dot" aria-hidden="true"></span>
                        <div class="ingredient-main">
                            <p class="ingredient-line">{{ $ingredient->line }}</p>
                            <div class="ingredient-match">
                                @if ($status === 'skipped')
                                    <span class="match-note">Skipped. You won't be asked to buy this.</span>
                                @elseif (! $product)
                                    <span class="match-note">Not in the catalog. <b>{{ $ingredient->name }}</b> is added when you stock this recipe.</span>
                                @else
                                    <span class="thumb">
                                        @if ($product->image)
                                            <img src="{{ $product->image }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <x-icon name="box" />
                                        @endif
                                    </span>
                                    <span class="match-product">
                                        <b>{{ $product->name }}</b>
                                        <small>{{ collect([$product->size, $product->price > 0 ? '₱'.number_format($product->price, 2) : null])->filter()->implode(' · ') }}</small>
                                        <small class="match-status">{{ [
                                            'home' => 'At home: '.($stock?->quantity ?? 0),
                                            'listed' => 'On your shopping list',
                                            'out' => 'In your pantry, but out of stock',
                                            'missing' => 'Not in your pantry yet',
                                        ][$status] }}</small>
                                    </span>
                                @endif
                            </div>
                        </div>
                        <button type="button" class="button secondary compact" data-picker
                                data-picker-url="{{ route('recipe-ingredients.update', $ingredient) }}"
                                data-name="{{ $ingredient->name }}"
                                data-skipped="{{ $ingredient->skip ? '1' : '0' }}">Change</button>
                    </li>
                @endforeach
            </ul>

            <form method="post" action="{{ route('recipes.stock', $recipe) }}" class="stock-footer">
                @csrf
                <div class="heading-actions">
                    <button class="button primary"><x-icon name="cart" />Stock ingredients</button>
                    <button type="button" class="button secondary" data-dialog="use-ingredients"><x-icon name="box" />Use ingredients</button>
                </div>
                <p class="muted">Stock ingredients adds anything missing to My pantry (and to the catalog if it isn't there yet), and puts every ingredient you have none of on your shopping list. Use ingredients takes what you cooked with out of My pantry.</p>
            </form>
        </section>

        <section class="steps-card">
            <div class="section-heading"><h2>Steps</h2></div>
            @if ($steps->isEmpty())
                <p class="muted">No steps saved. @if ($recipe->source_url)See the <a class="text-link" href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer">original recipe</a>.@endif</p>
            @else
                <ol class="step-list">
                    @foreach ($steps as $step)
                        @if (($step['type'] ?? 'step') === 'section')
                            <li class="step-section">{{ $step['text'] }}</li>
                        @else
                            <li class="recipe-step"><span class="step-number">{{ ++$stepNumber }}</span><p>{{ $step['text'] }}</p></li>
                        @endif
                    @endforeach
                </ol>
            @endif
            @if ($recipe->source_url)
                <p class="source-note">Recipe from <a class="text-link" href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer">{{ $recipe->sourceDomain() }}</a>@if ($recipe->chef) by {{ $recipe->chef->name }}@endif.</p>
            @endif
        </section>
    </div>
</article>

@include('partials.ingredient-picker')

{{-- "Use ingredients": what went into the dish comes out of My pantry. Reopens with the error if nothing was ticked. --}}
<dialog id="use-ingredients" class="use-dialog" aria-labelledby="use-title" @if ($errors->has('items')) data-open @endif>
    <form method="post" action="{{ route('recipes.use', $recipe) }}">
        @csrf
        <div class="dialog-heading">
            <h2 id="use-title">What did you use?</h2>
            <button type="button" class="icon-button" data-close-dialog aria-label="Close"><x-icon name="close" /></button>
        </div>
        <p class="muted use-intro">Tick what went into {{ $recipe->title }} and how many. It comes out of My pantry.</p>
        @error('items')
            <div class="notice error" role="alert"><div><p>{{ $message }}</p></div></div>
        @enderror

        @if ($usable->isEmpty())
            <div class="use-empty">
                <x-icon name="box" />
                <p>None of these ingredients are in your pantry yet. <b>Stock ingredients</b> adds them; then you can use them here.</p>
            </div>
        @else
            @if ($usable->every(fn ($row) => ! $row['stock']->quantity))
                <div class="use-empty">
                    <x-icon name="box" />
                    <p>You have none of these at home right now, so there's nothing to take out. <b>Stock ingredients</b> puts them on your shopping list.</p>
                </div>
            @endif
            <ul class="use-list">
                @foreach ($usable as $productId => ['stock' => $stock, 'product' => $product, 'lines' => $lines])
                    @php($atHome = $stock->quantity)
                    <li class="use-row {{ $atHome ? '' : 'is-empty' }}">
                        <label class="use-pick">
                            <input type="checkbox" name="items[{{ $productId }}][use]" value="1" data-use-toggle @checked($atHome > 0) @disabled(! $atHome)>
                            <span class="thumb">
                                @if ($product->image)
                                    <img src="{{ $product->image }}" alt="" loading="lazy" decoding="async">
                                @else
                                    <x-icon name="box" />
                                @endif
                            </span>
                            <span class="use-text">
                                <b>{{ $product->name }}</b>
                                <small>{{ $lines->implode('; ') }}</small>
                                <small class="use-home">{{ $atHome ? "At home: {$atHome}" : 'None at home' }}</small>
                            </span>
                        </label>
                        @if ($atHome)
                            <div class="use-stepper" data-use-stepper>
                                <button type="button" class="step" data-step="-1" aria-label="One fewer {{ $product->name }}">−</button>
                                <input type="number" name="items[{{ $productId }}][quantity]" value="1" min="1" max="{{ $atHome }}" inputmode="numeric" aria-label="How many {{ $product->name }}">
                                <button type="button" class="step" data-step="1" aria-label="One more {{ $product->name }}">+</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($notInPantry)
                <p class="muted use-note">{{ $notInPantry }} other {{ str('ingredient')->plural($notInPantry) }} {{ $notInPantry === 1 ? "isn't" : "aren't" }} in your pantry, so there's nothing to take out for {{ $notInPantry === 1 ? 'it' : 'them' }}.</p>
            @endif
            <label class="checkbox-label use-list-empty"><input type="checkbox" name="list_empty" value="1" checked>Put anything that runs out on my shopping list</label>
        @endif

        <div class="dialog-bottom">
            <button type="button" class="button secondary" data-close-dialog>Cancel</button>
            <button class="button primary" @disabled($usable->isEmpty() || $usable->every(fn ($row) => ! $row['stock']->quantity))><x-icon name="check" />Update my pantry</button>
        </div>
    </form>
</dialog>
@endsection
