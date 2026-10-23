@extends('layouts.app')
@section('title', 'Manage inventory')

@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">THE ESSENTIALS, WELL MANAGED</div>
        <h1>Manage your catalog.</h1>
        <p>One shared catalog. Everyone's pantry and shopping list pick from it.</p>
    </div>
    <a class="button primary" href="{{ route('admin.products.create') }}"><x-icon name="plus" />New product</a>
</div>

@php($photoPercent = $withPhotoUrl ? intdiv(100 * $localImages, $withPhotoUrl) : 100)
<div data-photo-status="{{ route('admin.status') }}" data-run-url="{{ route('admin.images.run') }}">
<div class="stats-grid">
    <div class="stat">
        <span class="stat-icon rose"><x-icon name="grid" /></span>
        <div><span>CATALOG PRODUCTS</span><strong data-status="total">{{ number_format($total) }}</strong></div>
    </div>
    <div class="stat">
        <span class="stat-icon sage"><x-icon name="check" /></span>
        <div><span>LOCAL PHOTOS</span><strong><span data-status="localImages">{{ number_format($localImages) }}</span> <small>/ <span data-status="withPhotoUrl">{{ number_format($withPhotoUrl) }}</span></small></strong></div>
    </div>
    <div class="stat">
        <span class="stat-icon amber"><x-icon name="upload" /></span>
        <div><span>QUEUED / FAILED</span><strong><span data-status="queued">{{ number_format($queued) }}</span> <small>/ <span data-status="failed">{{ number_format($failed) }}</span></small></strong></div>
    </div>
</div>

{{-- Photo downloads: live progress, and a way to download right now without the background worker. --}}
<section class="photo-queue">
    <div class="photo-queue-head">
        <div>
            <span class="eyebrow">PRODUCT PHOTOS</span>
            <h2><span data-queue-percent>{{ $photoPercent }}%</span> downloaded</h2>
            <p class="muted" data-queue-note>
                @if ($queued)
                    {{ number_format($queued) }} queued. The background worker downloads them, or tap Download now.
                @elseif ($localImages < $withPhotoUrl)
                    {{ number_format($withPhotoUrl - $localImages) }} photos aren't downloaded or queued. Queue them, then download.
                @else
                    Every product photo is on this server.
                @endif
            </p>
        </div>
        <div class="photo-queue-actions">
            <form method="post" action="{{ route('admin.images.retry') }}" data-busy="Queuing…">
                @csrf
                <button class="button secondary compact"><x-icon name="upload" />Queue missing photos</button>
            </form>
            <form method="post" action="{{ route('admin.images.run') }}" data-run-queue>
                @csrf
                <button class="button primary compact" data-run-button @disabled(! $queued)><x-icon name="arrow" /><span>Download now</span></button>
            </form>
        </div>
    </div>
    <div class="queue-bar" role="progressbar" aria-label="Photos downloaded" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $photoPercent }}"><span data-queue-bar style="width: {{ $photoPercent }}%"></span></div>
    <p class="queue-meta">
        <span><b data-status="localImages">{{ number_format($localImages) }}</b> of <span data-status="withPhotoUrl">{{ number_format($withPhotoUrl) }}</span> photos</span>
        <span data-queue-rate hidden></span>
        <span data-queue-eta hidden></span>
    </p>
</section>
</div>

<section class="import-panel">
    <div>
        <span class="eyebrow">BRING YOUR CATALOG ALONG</span>
        <h2>Import an inventory CSV</h2>
        <p>Columns: <code>sku, group, name, image, type, size, price</code></p>
        <p class="muted">Rows are matched by SKU: new ones are added, existing ones updated. Pantry stock and shopping lists are kept. Photos download to this server in the background, so the queue count above drops as they arrive.</p>
    </div>
    <form method="post" action="{{ route('admin.import') }}" enctype="multipart/form-data" class="import-form">
        @csrf
        <label class="sr-only" for="csv">Inventory CSV</label>
        <input id="csv" type="file" name="csv" accept=".csv,text/csv" required>
        <button class="button primary"><x-icon name="upload" />Import CSV</button>
    </form>
</section>

<div class="section-heading">
    <div>
        <h2>All products</h2>
        <span class="muted">{{ number_format($products->total()) }} shown</span>
    </div>
</div>

<form method="get" class="filters">
    <label class="search-field">
        <x-icon name="search" />
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by product or SKU" aria-label="Search products">
    </label>
    <select name="category" aria-label="Group">
        <option value="">All groups</option>
        @foreach ($categoryOptions as $option)
            <option value="{{ $option }}" @selected(request('category') === $option)>{{ $option }}</option>
        @endforeach
    </select>
    <select name="photo" aria-label="Photo">
        <option value="">Any photo</option>
        <option value="local" @selected(request('photo') === 'local')>Photo downloaded</option>
        <option value="missing" @selected(request('photo') === 'missing')>No local photo</option>
    </select>
    <button class="button secondary">Search</button>
    @if (request()->hasAny(['q', 'category', 'photo']))
        <a class="clear-link" href="{{ route('admin.index') }}">Clear</a>
    @endif
</form>

<div class="table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Group</th>
                <th>Size</th>
                <th>Price</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr>
                    <td>
                        <div class="product-cell">
                            <span class="thumb">
                                @if ($product->image)
                                    <img src="{{ $product->image }}" alt="" loading="lazy" decoding="async">
                                @else
                                    <x-icon name="box" />
                                @endif
                            </span>
                            <span><strong>{{ $product->name }}</strong><small>{{ $product->type }}</small></span>
                        </div>
                    </td>
                    <td data-label="SKU">{{ $product->sku }}</td>
                    <td data-label="Group">{{ $product->category }}</td>
                    <td data-label="Size">{{ $product->size ?: '—' }}</td>
                    <td data-label="Price">₱{{ number_format($product->price, 2) }}</td>
                    <td>
                        <div class="table-actions">
                            <a class="button secondary compact" href="{{ route('admin.products.edit', $product) }}">Edit</a>
                            <form method="post" action="{{ route('admin.products.destroy', $product) }}" data-confirm="Delete {{ $product->name }}? This also removes it from everyone's pantry and shopping list.">
                                @csrf
                                @method('DELETE')
                                <button class="icon-button text-danger" aria-label="Delete {{ $product->name }}"><x-icon name="trash" /></button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No products found. Import your CSV to get started.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $products->links() }}
@endsection
