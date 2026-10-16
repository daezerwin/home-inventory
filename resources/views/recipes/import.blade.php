@extends('layouts.app')
@section('title', 'Import a recipe')

@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">FROM ANY RECIPE SITE</div>
        <h1>Import a recipe.</h1>
        <p>Paste a link. The recipe, its photo and its ingredients are saved here, so you can keep using it even if the page changes.</p>
    </div>
    <a class="button secondary" href="{{ route('recipes.index') }}">Back to recipes</a>
</div>

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

    <button class="button primary"><x-icon name="upload" />Import recipe</button>

    <p class="muted">Works with recipe sites that publish their recipes in the standard format search engines read, which most do (Panlasang Pinoy and BBC Good Food, for example). A few large sites block apps from downloading their pages; add those by hand.</p>
</form>
@endsection
