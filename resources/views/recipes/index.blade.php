@extends('layouts.app')
@section('title', 'Recipes')

{{-- The landing view gets the full-bleed hero; searches and chef filters keep results up top. --}}
@php($landing = ! request()->hasAny(['q', 'chef']) && $recipes->onFirstPage())
@if ($landing)
    @section('hero')
    <x-parallax-hero :image="$heroImage">
        <span class="eyebrow">WHAT'S COOKING</span>
        <h1>Recipes.</h1>
        <p>Import a recipe from any link, then stock its ingredients in one tap.</p>
        <p class="hero-stats">
            <span><x-icon name="book" />{{ $recipes->total() }} {{ str('recipe')->plural($recipes->total()) }}</span>
            <span><x-icon name="chef" />{{ $chefs->count() }} {{ str('chef')->plural($chefs->count()) }}</span>
        </p>
        <div class="hero-actions">
            <a class="button hero-button" href="{{ route('recipes.import') }}"><x-icon name="plus" />Import from a link</a>
            <a class="button hero-button ghost" href="{{ route('recipes.create') }}"><x-icon name="edit" />Write one</a>
        </div>
    </x-parallax-hero>
    @endsection
@endif

@section('content')
@unless ($landing)
    <div class="page-heading">
        <div>
            <div class="eyebrow">WHAT'S COOKING</div>
            <h1>Recipes.</h1>
            <p>Import a recipe from any link, then stock its ingredients in one tap.</p>
        </div>
        <div class="heading-actions">
            <a class="button secondary" href="{{ route('recipes.create') }}"><x-icon name="edit" />Write one</a>
            <a class="button primary" href="{{ route('recipes.import') }}"><x-icon name="plus" />Import from a link</a>
        </div>
    </div>
@endunless

@if ($chefs->isNotEmpty())
    <nav class="filter-chips" aria-label="Chefs">
        <a class="chip {{ ! request('chef') ? 'selected' : '' }}" href="{{ route('recipes.index', request()->except('chef', 'page')) }}">All chefs</a>
        @foreach ($chefs as $chef)
            <a class="chip {{ request('chef') === $chef->slug ? 'selected' : '' }}" href="{{ route('recipes.index', [...request()->except('page'), 'chef' => $chef->slug]) }}">{{ $chef->name }}</a>
        @endforeach
        <a class="chip chip-link" href="{{ route('chefs.index') }}"><x-icon name="chef" />All chef pages</a>
    </nav>
@endif

<form class="filters" method="get">
    <label class="search-field">
        <x-icon name="search" />
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search recipes…" aria-label="Search recipes" enterkeyhint="search">
    </label>
    @if (request('chef'))
        <input type="hidden" name="chef" value="{{ request('chef') }}">
    @endif
    <button class="button secondary">Search</button>
    @if (request()->hasAny(['q', 'chef']))
        <a class="clear-link" href="{{ route('recipes.index') }}">Clear</a>
    @endif
</form>

<div class="recipe-grid">
    @forelse ($recipes as $recipe)
        @include('partials.recipe-card')
    @empty
        <div class="empty-state">
            <span class="stat-icon rose"><x-icon name="book" /></span>
            @if (request()->hasAny(['q', 'chef']))
                <h2>No recipes found.</h2>
                <p>Try another search or chef.</p>
            @else
                <h2>Your recipe box is empty.</h2>
                <p>Paste a link from a recipe site such as Panlasang Pinoy, and it's saved here with its ingredients matched to your catalog.</p>
            @endif
            <a class="button primary" href="{{ route('recipes.import') }}"><x-icon name="plus" />Import a recipe</a>
        </div>
    @endforelse
</div>

{{ $recipes->links() }}
@endsection
