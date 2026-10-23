<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#7c263e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Pantry">
    <title>@yield('title', 'Home Pantry') · Home Pantry</title>
    <link rel="icon" href="{{ asset('icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</head>
{{-- A page with a full-bleed hero gets a see-through navbar on top of it. --}}
<body @hasSection('hero') class="has-hero hero-@yield('hero-tone', 'dark')" @endif>
@php($onPantry = request()->routeIs('pantry'))
@php($browseRoute = $onPantry ? 'pantry' : 'catalog')

<div class="mobile-header">
    <a class="brand" href="{{ route('catalog') }}">
        <span class="brand-symbol"><x-icon name="home" /></span><span>home<span class="brand-light">pantry</span></span>
    </a>
    <a class="top-cart" href="{{ route('cart') }}" aria-label="Shopping list, {{ $cartCount }} to buy">
        <x-icon name="cart" /><span data-cart-count>{{ $cartCount }}</span>
    </a>
</div>

<div class="sidebar-overlay" data-close-menu></div>
<aside class="sidebar" id="sidebar" aria-label="Menu">
    <div class="sidebar-head">
        <a class="brand" href="{{ route('catalog') }}">
            <span class="brand-symbol"><x-icon name="home" /></span>
            <span>home<span class="brand-light">pantry</span><small>A LITTLE MORE ORGANIZED</small></span>
        </a>
        <button type="button" class="icon-button" data-close-menu aria-label="Close menu"><x-icon name="close" /></button>
    </div>

    <nav aria-label="Main navigation">
        <a class="nav-link {{ request()->routeIs('catalog') ? 'active' : '' }}" href="{{ route('catalog') }}"><x-icon name="grid" />Discover items</a>
        <a class="nav-link {{ $onPantry ? 'active' : '' }}" href="{{ route('pantry') }}"><x-icon name="box" />My pantry</a>
        <a class="nav-link {{ request()->routeIs('cart') ? 'active' : '' }}" href="{{ route('cart') }}">
            <x-icon name="cart" />Shopping list <span class="nav-count" data-cart-count>{{ $cartCount }}</span>
        </a>
        <a class="nav-link {{ request()->routeIs('recipes.*') ? 'active' : '' }}" href="{{ route('recipes.index') }}"><x-icon name="book" />Recipes</a>
        <a class="nav-link {{ request()->routeIs('chefs.*') ? 'active' : '' }}" href="{{ route('chefs.index') }}"><x-icon name="chef" />Chefs</a>

        <p class="nav-caption">{{ $onPantry ? 'MY PANTRY BY GROUP' : 'BROWSE GROUPS' }} <span>{{ $categories->count() }}</span></p>
        <a class="category-link {{ ! request('category') && request()->routeIs('catalog', 'pantry') ? 'selected' : '' }}" href="{{ route($browseRoute) }}">
            <span>All items</span><span>{{ number_format($categories->sum('total')) }}</span>
        </a>
        @foreach ($categories as $category)
            <a class="category-link {{ request('category') === $category->category ? 'selected' : '' }}" href="{{ route($browseRoute, ['category' => $category->category]) }}">
                <span>{{ $category->category }}</span><span>{{ number_format($category->total) }}</span>
            </a>
        @endforeach
    </nav>

    <div class="sidebar-bottom">
        @if (auth()->user()->is_admin)
            <a class="nav-link {{ request()->routeIs('admin.*') ? 'active' : '' }}" href="{{ route('admin.index') }}"><x-icon name="settings" />Manage inventory</a>
        @endif
        <div class="profile">
            <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            <div>
                <strong>{{ auth()->user()->name }}</strong>
                <small>{{ auth()->user()->is_admin ? 'Home administrator' : 'My personal pantry' }}</small>
            </div>
            <form method="post" action="{{ route('logout') }}" data-logout>
                @csrf
                <button class="icon-button" title="Sign out" aria-label="Sign out"><x-icon name="logout" /></button>
            </form>
        </div>
    </div>
</aside>

<div class="workspace">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="icon-button menu-button" data-menu aria-expanded="false" aria-controls="sidebar" aria-label="Open menu"><x-icon name="menu" /></button>
            <a class="brand" href="{{ route('catalog') }}">
                <span class="brand-symbol"><x-icon name="home" /></span><span>home<span class="brand-light">pantry</span></span>
            </a>
            <div class="breadcrumb">My home <span>/</span> <strong>@yield('title', 'Discover items')</strong></div>
        </div>
        <div class="topbar-right">
            <span class="today">{{ now()->format('l, M j') }}</span>
            <a class="top-cart" href="{{ route('cart') }}" aria-label="Shopping list, {{ $cartCount }} to buy">
                <x-icon name="cart" /><span data-cart-count>{{ $cartCount }}</span>
            </a>
        </div>
    </header>

    @hasSection('hero')
        <div class="hero-slot" data-hero>@yield('hero')</div>
    @endif

    <main id="main-content">
        @if ($errors->any())
            <div class="notice error" role="alert">
                <div>@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            </div>
        @endif

        @yield('content')

        <footer class="page-footer">
            <span>Less guessing. More good things at home.</span>
            <span><x-icon name="home" /> Your home, in order.</span>
        </footer>
    </main>
</div>

{{-- Phone navigation: "Menu" opens the sidebar drawer. --}}
<nav class="tab-bar" aria-label="Quick navigation">
    <a class="{{ request()->routeIs('catalog') ? 'active' : '' }}" href="{{ route('catalog') }}"><x-icon name="grid" /><span>Discover</span></a>
    <a class="{{ $onPantry ? 'active' : '' }}" href="{{ route('pantry') }}"><x-icon name="box" /><span>Pantry</span></a>
    <a class="{{ request()->routeIs('cart') ? 'active' : '' }}" href="{{ route('cart') }}">
        <x-icon name="cart" /><span>List</span><b class="tab-badge" data-cart-count @if (! $cartCount) hidden @endif>{{ $cartCount }}</b>
    </a>
    <a class="{{ request()->routeIs('recipes.*', 'chefs.*') ? 'active' : '' }}" href="{{ route('recipes.index') }}"><x-icon name="book" /><span>Recipes</span></a>
    <button type="button" data-menu aria-expanded="false" aria-controls="sidebar"><x-icon name="menu" /><span>Menu</span></button>
</nav>

<div class="toast-region" aria-live="polite">
    @if (session('success'))
        <div class="toast" role="status"><x-icon name="check" /><span>{{ session('success') }}</span></div>
    @endif
</div>
</body>
</html>
