@extends('layouts.app')
@section('title', 'Chefs')

@section('hero')
<x-parallax-hero :image="$heroImage">
    <span class="eyebrow">WHERE YOUR RECIPES COME FROM</span>
    <h1>Chefs.</h1>
    <p>Every cook, blog and kitchen in your recipe box, each with a page of their recipes.</p>
    <p class="hero-stats">
        <span><x-icon name="chef" />{{ $chefs->count() }} {{ str('chef')->plural($chefs->count()) }}</span>
        <span><x-icon name="book" />{{ $recipeCount }} {{ str('recipe')->plural($recipeCount) }}</span>
    </p>
    <a class="button hero-button" href="{{ route('recipes.import') }}"><x-icon name="plus" />Import a recipe</a>
</x-parallax-hero>
@endsection

@section('content')
<div class="chef-grid">
    @forelse ($chefs as $chef)
        @php($cover = $chef->hero_photo ?? $chef->heroRecipe?->image ?? $chef->recipes->first()?->image)
        <a class="chef-card" href="{{ route('chefs.show', $chef) }}">
            <div class="chef-cover {{ $cover ? '' : 'no-image' }}">
                @if ($cover)
                    <img src="{{ $cover }}" alt="" loading="lazy" decoding="async">
                @endif
                <span class="chef-avatar">{{ $chef->initials() }}</span>
            </div>
            <div class="chef-info">
                <h3>{{ $chef->name }}</h3>
                <p>{{ collect([$chef->site_name !== $chef->name ? $chef->site_name : null, $chef->domain()])->filter()->implode(' · ') ?: 'Home kitchen' }}</p>
                <span class="chef-count"><x-icon name="book" />{{ $chef->recipes_count }} {{ str('recipe')->plural($chef->recipes_count) }}</span>
            </div>
        </a>
    @empty
        <div class="empty-state">
            <span class="stat-icon rose"><x-icon name="chef" /></span>
            <h2>No chefs yet.</h2>
            <p>Chefs appear here as you import recipes. Each recipe is tagged with where it came from.</p>
            <a class="button primary" href="{{ route('recipes.import') }}"><x-icon name="plus" />Import a recipe</a>
        </div>
    @endforelse
</div>
@endsection
