@extends('layouts.app')
@section('title', $chef->name)

@section('hero')
<x-parallax-hero :image="$heroImage">
    <a class="eyebrow" href="{{ route('chefs.index') }}">CHEFS</a>
    <h1>{{ $chef->name }}</h1>
    @if ($chef->site_name && $chef->site_name !== $chef->name)
        <p class="hero-subtitle">{{ $chef->site_name }}</p>
    @endif
    <p class="hero-stats">
        <span><x-icon name="book" />{{ $recipes->total() }} {{ str('recipe')->plural($recipes->total()) }}</span>
        @if ($chef->website)
            <a href="{{ $chef->website }}" target="_blank" rel="noopener noreferrer"><x-icon name="external" />{{ $chef->domain() }}</a>
        @endif
    </p>
    <div class="hero-actions">
        {{-- Opens the editor below; without JavaScript it is a link to the edit page. --}}
        <a class="button hero-button" href="{{ route('chefs.edit', $chef) }}" data-dialog="chef-editor"><x-icon name="edit" />Edit chef</a>
        @if ($canDelete)
            @include('partials.chef-delete', ['class' => '', 'buttonClass' => 'button hero-button ghost'])
        @endif
    </div>
</x-parallax-hero>
@endsection

@section('content')
<section class="chef-about">
    <div class="chef-bio">
        <h2>About {{ $chef->name }}</h2>
        @if ($chef->bio)
            @foreach (preg_split('/\R\s*\R/', trim($chef->bio)) as $paragraph)
                <p>{!! nl2br(e($paragraph)) !!}</p>
            @endforeach
        @else
            <p class="muted">No bio yet. Add a few lines about where {{ $chef->name }} cooks and what they're known for.</p>
            <a class="button secondary compact" href="{{ route('chefs.edit', $chef) }}" data-dialog="chef-editor"><x-icon name="plus" />Add a bio</a>
        @endif
    </div>
    <dl class="chef-facts">
        <div>
            <dt>Site or kitchen</dt>
            <dd>
                @if ($chef->site_name)
                    {{ $chef->site_name }}
                @else
                    <a class="text-link" href="{{ route('chefs.edit', $chef) }}" data-dialog="chef-editor">Add</a>
                @endif
            </dd>
        </div>
        <div>
            <dt>Website</dt>
            <dd>
                @if ($chef->website)
                    <a class="text-link" href="{{ $chef->website }}" target="_blank" rel="noopener noreferrer">{{ $chef->domain() }}</a>
                @else
                    <a class="text-link" href="{{ route('chefs.edit', $chef) }}" data-dialog="chef-editor">Add</a>
                @endif
            </dd>
        </div>
        <div>
            <dt>In your recipe box</dt>
            <dd>{{ $recipes->total() }} {{ str('recipe')->plural($recipes->total()) }}</dd>
        </div>
    </dl>
</section>

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

{{-- Slide-over editor; reopens with the errors when a save doesn't validate. --}}
<dialog id="chef-editor" class="sheet" aria-labelledby="chef-editor-title" @if ($errors->any()) data-open @endif>
    <div class="sheet-head">
        <h2 id="chef-editor-title">Edit chef</h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Close"><x-icon name="close" /></button>
    </div>
    <div class="sheet-body">
        <form id="chef-form" class="stack-form" method="post" action="{{ route('chefs.update', $chef) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            @if ($errors->any())
                <div class="notice error" role="alert">
                    <div>@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
                </div>
            @endif
            @include('partials.chef-fields')
        </form>
        @if ($canDelete)
            @include('partials.chef-delete')
        @endif
    </div>
    <div class="sheet-foot">
        <button type="button" class="button secondary" data-close-dialog>Cancel</button>
        <button class="button primary" form="chef-form">Save chef</button>
    </div>
</dialog>
@endsection
