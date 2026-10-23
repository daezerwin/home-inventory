@extends('layouts.app')
@section('title', 'Import a recipe')

@section('content')
@include('partials.import-steps', ['step' => 1])

<div class="page-heading">
    <div>
        <div class="eyebrow">STEP 1 OF 3 · FROM ANY RECIPE SITE</div>
        <h1>Import a recipe.</h1>
        <p>Paste a link. Next you pick what to buy for each ingredient, then the recipe, its photo and its ingredients are saved here.</p>
    </div>
    <a class="button secondary" href="{{ route('recipes.index') }}">Back to recipes</a>
</div>

@if ($drafts->isNotEmpty())
    <section class="draft-list" aria-label="Unfinished imports">
        <h2>Pick up where you left off</h2>
        @foreach ($drafts as $draft)
            <div class="draft-row">
                <span class="thumb">
                    @if ($draft->image)
                        <img src="{{ $draft->image }}" alt="" loading="lazy" decoding="async">
                    @else
                        <x-icon name="book" />
                    @endif
                </span>
                <div class="draft-text">
                    <b>{{ $draft->title }}</b>
                    <small>{{ $draft->ingredients_count }} {{ str('ingredient')->plural($draft->ingredients_count) }} · started {{ $draft->created_at->diffForHumans() }}</small>
                </div>
                <a class="button secondary compact" href="{{ route('recipes.review', $draft) }}">Continue</a>
                <form method="post" action="{{ route('recipes.discard', $draft) }}" data-confirm-button="Discard" data-confirm="Discard this import? The recipe won't be saved.">
                    @csrf
                    @method('DELETE')
                    <button class="icon-button" aria-label="Discard {{ $draft->title }}" title="Discard"><x-icon name="trash" /></button>
                </form>
            </div>
        @endforeach
    </section>
@endif

@if (session('manual_url'))
    <div class="notice manual-fallback">
        <x-icon name="edit" />
        <span>You can still add this one yourself: copy the ingredients and steps from the page into the recipe form.</span>
        <a class="button secondary compact" href="{{ route('recipes.create', ['source_url' => session('manual_url')]) }}">Add it by hand</a>
    </div>
@endif

<form class="editor-panel stack-form import-recipe-form" method="post" action="{{ route('recipes.import') }}" data-busy="Importing… this takes a few seconds">
    @csrf
    <label>Recipe link
        <input type="url" name="url" value="{{ old('url') }}" required maxlength="2048" autofocus inputmode="url" placeholder="https://panlasangpinoy.com/adobong-talong-eggplant-adobo-recipe/">
    </label>

    <div class="form-columns">
        <label>Chef
            <select name="chef_id">
                <option value="">Detect from the page</option>
                @foreach ($chefs as $chef)
                    <option value="{{ $chef->id }}" @selected(old('chef_id') == $chef->id)>{{ $chef->name }}</option>
                @endforeach
            </select>
        </label>
        <label>…or a new chef
            <input name="new_chef" value="{{ old('new_chef') }}" maxlength="120" placeholder="e.g. Lola's kitchen">
        </label>
    </div>
    <p class="muted">The chef groups recipes by where they come from. "Detect" uses the recipe's author, or else the site's name.</p>

    <button class="button primary">Next: pick ingredients<x-icon name="arrow" /></button>

    <p class="muted">Works with recipe sites that publish their recipes in the standard format search engines read, which most do (Panlasang Pinoy and BBC Good Food, for example). A few large sites block apps from downloading their pages; add those by hand.</p>
</form>
@endsection
