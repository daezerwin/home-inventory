@extends('layouts.app')
@section('title', 'Edit chef')

@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">CHEF PAGE</div>
        <h1>Edit {{ $chef->name }}.</h1>
        <p>Name, a short bio, and which recipe photo fills the top of the page.</p>
    </div>
    <a class="button secondary" href="{{ route('chefs.show', $chef) }}">Cancel</a>
</div>

<form class="editor-panel stack-form" method="post" action="{{ route('chefs.update', $chef) }}">
    @csrf
    @method('PUT')
    <div class="form-columns">
        <label>Name<input name="name" value="{{ old('name', $chef->name) }}" required maxlength="120"></label>
        <label>Site or kitchen<input name="site_name" value="{{ old('site_name', $chef->site_name) }}" maxlength="120" placeholder="e.g. Panlasang Pinoy"></label>
    </div>
    <label>Website<input type="url" name="website" value="{{ old('website', $chef->website) }}" maxlength="255" placeholder="https://…"></label>
    <label>Bio<textarea name="bio" rows="4" maxlength="5000">{{ old('bio', $chef->bio) }}</textarea></label>

    <fieldset class="hero-picker">
        <legend>Page photo</legend>
        <label class="hero-option">
            <input type="radio" name="hero_recipe_id" value="" @checked(! old('hero_recipe_id', $chef->hero_recipe_id))>
            <span class="hero-option-auto">Newest recipe</span>
        </label>
        @foreach ($photoRecipes as $recipe)
            <label class="hero-option">
                <input type="radio" name="hero_recipe_id" value="{{ $recipe->id }}" @checked(old('hero_recipe_id', $chef->hero_recipe_id) == $recipe->id)>
                <img src="{{ $recipe->image }}" alt="{{ $recipe->title }}" loading="lazy">
            </label>
        @endforeach
    </fieldset>

    <button class="button primary">Save chef</button>
</form>

@if (auth()->user()->is_admin)
    <form class="danger-zone" method="post" action="{{ route('chefs.destroy', $chef) }}" data-confirm="Remove {{ $chef->name }}? Their recipes are kept, without a chef.">
        @csrf
        @method('DELETE')
        <button class="button secondary danger"><x-icon name="trash" />Remove chef</button>
    </form>
@endif
@endsection
