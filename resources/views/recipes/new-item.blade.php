@extends('layouts.app')
@section('title', 'New item')

@section('content')
@include('partials.import-steps', ['step' => 2])

<div class="page-heading">
    <div>
        <div class="eyebrow">NEW CATALOG ITEM</div>
        <h1>Add {{ $ingredient->name }}.</h1>
        <p>For “{{ $ingredient->line }}”. It joins the catalog and is picked for this ingredient, then you're back in the wizard.</p>
    </div>
    <a class="button secondary" href="{{ route('recipes.review', $recipe) }}#ingredient-{{ $ingredient->id }}">Back to the wizard</a>
</div>

<form class="editor-panel stack-form" method="post" action="{{ route('recipes.new-item', [$recipe, $ingredient]) }}">
    @csrf
    <label>Name<input name="name" value="{{ old('name', $ingredient->name) }}" required maxlength="120" autofocus></label>
    <div class="form-columns three">
        <label>Group
            <select name="category">
                @php($groups = $categories->pluck('category')->push(\App\Services\IngredientMatcher::NEW_PRODUCT_GROUP)->unique()->sort())
                @foreach ($groups as $group)
                    <option value="{{ $group }}" @selected(old('category', \App\Services\IngredientMatcher::NEW_PRODUCT_GROUP) === $group)>{{ $group }}</option>
                @endforeach
            </select>
        </label>
        <label>Size (optional)<input name="size" value="{{ old('size') }}" maxlength="60" placeholder="e.g. 500g"></label>
        <label>Price (optional)<input type="number" name="price" value="{{ old('price') }}" min="0" step="0.01" inputmode="decimal" placeholder="0.00"></label>
    </div>
    <button class="button primary"><x-icon name="plus" />Add to the catalog</button>
</form>
@endsection
