@extends('layouts.app')
@section('title', $recipe->exists ? 'Edit recipe' : 'New recipe')

@php
    $ingredientText = old('ingredients', $recipe->exists ? $recipe->ingredients->pluck('line')->implode("\n") : '');
    $stepText = old('instructions', collect($recipe->instructions ?? [])
        ->map(fn ($step) => ($step['type'] ?? 'step') === 'section' ? '# '.$step['text'] : $step['text'])
        ->implode("\n"));
    $canDelete = $recipe->exists && (auth()->user()->is_admin || $recipe->user_id === auth()->id());
@endphp

@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">{{ $recipe->exists ? 'MAKE IT YOURS' : 'FROM YOUR OWN KITCHEN' }}</div>
        <h1>{{ $recipe->exists ? 'Edit recipe.' : 'Write a recipe.' }}</h1>
        <p>Ingredients go one per line, as you'd read them ("5 cloves garlic, chopped"). Each line is matched to the catalog when you save.</p>
    </div>
    <a class="button secondary" href="{{ $recipe->exists ? route('recipes.show', $recipe) : route('recipes.index') }}">Cancel</a>
</div>

<div class="editor-layout">
    <form class="editor-panel stack-form" method="post" action="{{ $recipe->exists ? route('recipes.update', $recipe) : route('recipes.store') }}">
        @csrf
        @if ($recipe->exists)
            @method('PUT')
        @endif

        <label>Title<input name="title" value="{{ old('title', $recipe->title) }}" required maxlength="250"></label>

        <div class="form-columns">
            <label>Chef
                <select name="chef_id">
                    <option value="">No chef</option>
                    @foreach ($chefs as $chef)
                        <option value="{{ $chef->id }}" @selected(old('chef_id', $recipe->chef_id) == $chef->id)>{{ $chef->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>…or a new chef<input name="new_chef" value="{{ old('new_chef') }}" maxlength="120" placeholder="e.g. Lola's kitchen"></label>
        </div>

        <label>Short description<textarea name="description" rows="3" maxlength="5000">{{ old('description', $recipe->description) }}</textarea></label>

        <div class="form-columns three">
            <label>Prep (min)<input type="number" inputmode="numeric" name="prep_minutes" min="0" max="65000" value="{{ old('prep_minutes', $recipe->prep_minutes) }}"></label>
            <label>Cook (min)<input type="number" inputmode="numeric" name="cook_minutes" min="0" max="65000" value="{{ old('cook_minutes', $recipe->cook_minutes) }}"></label>
            <label>Total (min)<input type="number" inputmode="numeric" name="total_minutes" min="0" max="65000" value="{{ old('total_minutes', $recipe->total_minutes) }}"></label>
        </div>
        <label>Serves<input name="servings" value="{{ old('servings', $recipe->servings) }}" maxlength="120" placeholder="e.g. 4 people"></label>

        <label>Ingredients <small class="muted">one per line</small>
            <textarea name="ingredients" rows="10" required maxlength="20000" placeholder="3 pieces Chinese eggplant&#10;5 cloves garlic, chopped&#10;3 tablespoons soy sauce">{{ $ingredientText }}</textarea>
        </label>
        <label>Steps <small class="muted">one per line; start a line with # for a heading</small>
            <textarea name="instructions" rows="10" maxlength="50000">{{ $stepText }}</textarea>
        </label>

        <div class="form-columns">
            <label>Original recipe link<input type="url" name="source_url" value="{{ old('source_url', $recipe->source_url) }}" maxlength="2048" placeholder="https://…"></label>
            <label>Photo URL<input type="url" name="image_url" value="{{ old('image_url', $recipe->image_url) }}" maxlength="2048" placeholder="https://…/photo.jpg"></label>
        </div>
        <p class="muted">A local copy of the photo is saved, like product photos.</p>

        <button class="button primary">{{ $recipe->exists ? 'Save recipe' : 'Add recipe' }}</button>
    </form>

    @if ($recipe->exists)
        <aside class="editor-side">
            <div class="product-photo">
                @if ($recipe->image)
                    <img src="{{ $recipe->image }}" alt="{{ $recipe->title }}" loading="lazy" decoding="async">
                @else
                    <div class="photo-placeholder"><x-icon name="book" /><span>No photo</span></div>
                @endif
            </div>
            @if ($canDelete)
                <form method="post" action="{{ route('recipes.destroy', $recipe) }}" data-confirm="Delete {{ $recipe->title }}? Pantry items and catalog products it added are kept.">
                    @csrf
                    @method('DELETE')
                    <button class="button secondary danger"><x-icon name="trash" />Delete recipe</button>
                </form>
            @endif
        </aside>
    @endif
</div>
@endsection
