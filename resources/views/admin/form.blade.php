@extends('layouts.app')
@section('title', $product->exists ? 'Edit product' : 'New product')

@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">CURATE YOUR EVERYDAY</div>
        <h1>{{ $product->exists ? 'Edit product.' : 'Something new for home.' }}</h1>
        <p>Fields follow the inventory CSV: sku, group, name, image, type, size and price.</p>
    </div>
    <a class="button secondary" href="{{ route('admin.index') }}">Back to catalog</a>
</div>

<div class="editor-layout">
    <form class="editor-panel stack-form" method="post" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}">
        @csrf
        @if ($product->exists)
            @method('PUT')
        @endif

        <label>Product name<input name="name" value="{{ old('name', $product->name) }}" required maxlength="255"></label>
        <div class="form-columns">
            <label>SKU<input name="sku" value="{{ old('sku', $product->sku) }}" required maxlength="255"></label>
            <label>Group<input name="category" value="{{ old('category', $product->category) }}" required maxlength="255" list="category-options"></label>
        </div>
        <datalist id="category-options">
            @foreach ($categories as $category)
                <option value="{{ $category->category }}">
            @endforeach
        </datalist>
        <div class="form-columns">
            <label>Type<input name="type" value="{{ old('type', $product->type) }}" maxlength="255" placeholder="e.g. Canned Goods"></label>
            <label>Size<input name="size" value="{{ old('size', $product->size) }}" maxlength="255" placeholder="e.g. 500g"></label>
        </div>
        <label>Catalog price (₱)<input name="price" type="number" inputmode="decimal" min="0" max="9999999999.99" step="0.01" value="{{ old('price', $product->price ?? 0) }}" required></label>
        <label>Image URL<input name="image_url" type="url" value="{{ old('image_url', $product->image_url) }}" maxlength="2048" placeholder="https://…"></label>
        <p class="muted">After saving, a local copy of the photo is downloaded in the background. Change the URL to replace it.</p>
        <button class="button primary">{{ $product->exists ? 'Save changes' : 'Create product' }}</button>
    </form>

    @if ($product->exists)
        <aside class="editor-side">
            <div class="product-photo">
                @if ($product->image)
                    <img src="{{ $product->image }}" alt="{{ $product->name }}" loading="lazy" decoding="async">
                @else
                    <div class="photo-placeholder"><x-icon name="box" /><span>{{ $product->image_url ? 'Photo downloading…' : 'No photo' }}</span></div>
                @endif
            </div>
            <form method="post" action="{{ route('admin.products.destroy', $product) }}" data-confirm="Delete {{ $product->name }}? This also removes it from everyone's pantry and shopping list.">
                @csrf
                @method('DELETE')
                <button class="button secondary danger"><x-icon name="trash" />Delete product</button>
            </form>
        </aside>
    @endif
</div>
@endsection
