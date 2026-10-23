@extends('layouts.app')
@section('title', 'Edit chef')

@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">CHEF PAGE</div>
        <h1>Edit {{ $chef->name }}.</h1>
        <p>Name, a short bio, and the photo that fills the top of the page.</p>
    </div>
    <a class="button secondary" href="{{ route('chefs.show', $chef) }}">Cancel</a>
</div>

<form class="editor-panel stack-form" method="post" action="{{ route('chefs.update', $chef) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('partials.chef-fields')
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
