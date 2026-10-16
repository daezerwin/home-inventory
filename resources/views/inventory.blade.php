@extends('layouts.app')
@section('title', $mode === 'pantry' ? 'My pantry' : 'Discover items')

@section('content')
@php($route = $mode === 'pantry' ? 'pantry' : 'catalog')
@php($filtered = request()->hasAny(['q', 'category', 'status']))

<div class="page-heading">
    <div>
        <div class="eyebrow">{{ $mode === 'pantry' ? 'YOUR EVERYDAY ESSENTIALS' : 'A WELL-STOCKED HOME STARTS HERE' }}</div>
        <h1>{{ $mode === 'pantry' ? 'My pantry' : 'Good things for your home.' }}</h1>
        <p>{{ $mode === 'pantry' ? 'Know what you have. Make room for what you need.' : 'Explore the catalog, pick your essentials, and keep life stocked.' }}</p>
    </div>
    @if ($mode === 'pantry')
        <form method="post" action="{{ route('pantry.restock') }}">
            @csrf
            <button class="button primary" @disabled(! $stats['low'])><x-icon name="cart" />Restock low items</button>
        </form>
    @else
        <a class="button primary" href="{{ route('pantry') }}">Go to my pantry<x-icon name="arrow" /></a>
    @endif
</div>

<div class="stats-grid">
    <a class="stat" href="{{ route($mode === 'pantry' ? 'catalog' : 'pantry') }}">
        <span class="stat-icon rose"><x-icon name="{{ $mode === 'pantry' ? 'box' : 'grid' }}" /></span>
        <div>
            <span>{{ $mode === 'pantry' ? 'IN MY PANTRY' : 'IN THE CATALOG' }}</span>
            <strong>{{ number_format($mode === 'pantry' ? $stats['tracked'] : $stats['catalog']) }}</strong>
        </div>
    </a>
    <a class="stat" href="{{ route('pantry') }}">
        <span class="stat-icon sage"><x-icon name="home" /></span>
        <div><span>UNITS AT HOME</span><strong>{{ number_format($stats['units']) }}</strong></div>
    </a>
    <a class="stat" href="{{ route('pantry', ['status' => 'low']) }}">
        <span class="stat-icon amber"><x-icon name="alert" /></span>
        <div><span>NEEDS A REFILL</span><strong>{{ number_format($stats['low']) }}</strong></div>
        <x-icon name="arrow" class="stat-arrow" />
    </a>
</div>

@if ($mode === 'catalog' && ! $filtered && $products->onFirstPage())
    <section class="welcome-banner">
        <div class="banner-copy">
            <span class="eyebrow">A LITTLE PLANNING, A HAPPIER HOME</span>
            <h2>Your essentials.<br>Always within reach.</h2>
            <p>Add your favorites to My pantry and say goodbye<br class="desktop-only"> to “do we still have this?”</p>
            <a href="{{ route('pantry') }}">Make yourself at home <x-icon name="arrow" /></a>
        </div>
        <div class="pantry-art" aria-hidden="true">
            <span class="art-circle"></span>
            <div class="jar jar-one"><div class="jar-lid"></div><div class="jar-label">PANTRY<br><b>the everyday</b><small>GOOD THINGS INSIDE</small></div></div>
            <div class="bottle"><div class="bottle-cap"></div><div class="bottle-label"><x-icon name="leaf" /><b>olive</b><small>EXTRA VIRGIN</small></div></div>
            <div class="jar jar-two"><div class="jar-lid"></div><div class="jar-label">HOME<br><b>little essentials</b></div></div>
            <span class="art-shelf"></span>
            <span class="art-spark">✧</span>
        </div>
    </section>
@endif

<section class="catalog-section">
    {{-- On phones the groups from the sidebar become a swipeable row. --}}
    <nav class="chip-row" aria-label="Groups">
        <a class="chip {{ ! request('category') ? 'selected' : '' }}" href="{{ route($route, request()->except('category', 'page')) }}">All</a>
        @foreach ($categories as $category)
            <a class="chip {{ request('category') === $category->category ? 'selected' : '' }}" href="{{ route($route, [...request()->except('page'), 'category' => $category->category]) }}">{{ $category->category }}</a>
        @endforeach
    </nav>

    <div class="section-heading">
        <div>
            <h2>{{ request('category') ?: ($mode === 'pantry' ? 'Your home collection' : 'Explore the essentials') }}</h2>
            <span class="muted">{{ number_format($products->total()) }} {{ $mode === 'pantry' ? 'items in your pantry' : 'items in the catalog' }}</span>
        </div>
        <div class="view-switch">
            <a aria-label="Grid view" class="{{ request('view') !== 'list' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['view' => 'grid']) }}"><x-icon name="grid" /></a>
            <a aria-label="List view" class="{{ request('view') === 'list' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"><x-icon name="list" /></a>
        </div>
    </div>

    <form class="filters" method="get" action="{{ route($route) }}">
        <label class="search-field">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $mode === 'pantry' ? 'Search your pantry…' : 'Search '.number_format($stats['catalog']).' everyday essentials…' }}" aria-label="Search items" enterkeyhint="search">
        </label>
        @if (request('category'))
            <input type="hidden" name="category" value="{{ request('category') }}">
        @endif
        <input type="hidden" name="view" value="{{ request('view', 'grid') }}">
        @if ($mode === 'pantry')
            <select name="status" aria-label="Stock status">
                <option value="">All stock levels</option>
                <option value="low" @selected(request('status') === 'low')>Needs a refill</option>
                <option value="out" @selected(request('status') === 'out')>Out of stock</option>
            </select>
        @endif
        <select name="sort" aria-label="Sort items">
            <option value="name">Name: A to Z</option>
            <option value="price-asc" @selected(request('sort') === 'price-asc')>Price: low to high</option>
            <option value="price-desc" @selected(request('sort') === 'price-desc')>Price: high to low</option>
        </select>
        <button class="button secondary">Apply</button>
        @if ($filtered)
            <a class="clear-link" href="{{ route($route) }}">Clear</a>
        @endif
    </form>

    <div class="product-grid {{ request('view') === 'list' ? 'list-view' : '' }}">
        @forelse ($products as $product)
            @include('partials.product-card')
        @empty
            <div class="empty-state">
                <span class="stat-icon rose"><x-icon name="box" /></span>
                @if ($mode === 'pantry' && ! $filtered)
                    <h2>A fresh start for your shelves.</h2>
                    <p>Browse the catalog and tap <b>My pantry</b> on the things you keep at home. Then track how many you have and restock in one tap.</p>
                @else
                    <h2>No items found.</h2>
                    <p>Try another search or group.@if (auth()->user()->is_admin) Admins can add products or import a catalog CSV.@endif</p>
                @endif
                <a class="button primary" href="{{ route('catalog') }}">Explore the catalog<x-icon name="arrow" /></a>
            </div>
        @endforelse
    </div>

    {{ $products->links() }}
</section>
@endsection
