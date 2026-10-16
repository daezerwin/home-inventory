@extends('layouts.app')
@section('title', $chef->name)

@section('content')
<x-parallax-hero :image="$heroImage">
    <a class="eyebrow" href="{{ route('chefs.index') }}">CHEFS</a>
    <h1>{{ $chef->name }}</h1>
    @if ($chef->site_name && $chef->site_name !== $chef->name)
        <p class="hero-subtitle">{{ $chef->site_name }}</p>
    @endif
    @if ($chef->bio)
        <p>{{ $chef->bio }}</p>
    @endif
    <p class="hero-stats">
        <span><x-icon name="book" />{{ $recipes->total() }} {{ str('recipe')->plural($recipes->total()) }}</span>
        @if ($chef->website)
            <a href="{{ $chef->website }}" target="_blank" rel="noopener noreferrer"><x-icon name="external" />{{ $chef->domain() }}</a>
        @endif
    </p>
    <a class="button hero-button" href="{{ route('chefs.edit', $chef) }}"><x-icon name="edit" />Edit chef</a>
</x-parallax-hero>

<div class="section-heading">
    <div>
        <h2>Recipes by {{ $chef->name }}</h2>
        <span class="muted">{{ $recipes->total() }} in your recipe box</span>
    </div>
    <a class="button secondary compact" href="{{ route('recipes.import') }}"><x-icon name="plus" />Import a recipe</a>
</div>

<div class="recipe-grid">
    @forelse ($recipes as $recipe)
        @include('partials.recipe-card', ['showChef' => false])
    @empty
        <div class="empty-state">
            <span class="stat-icon rose"><x-icon name="book" /></span>
            <h2>No recipes yet.</h2>
            <p>Import a recipe and choose {{ $chef->name }} as its chef.</p>
        </div>
    @endforelse
</div>

{{ $recipes->links() }}
@endsection
