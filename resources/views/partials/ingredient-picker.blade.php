{{-- One picker shared by every [data-picker] button; app.js points its forms at the ingredient. --}}
<dialog id="ingredient-picker" class="picker" aria-labelledby="picker-title" data-search-url="{{ route('products.search') }}">
    <div class="dialog-heading">
        <h2 id="picker-title">Buy <span data-picker-name></span> as…</h2>
        <button type="button" class="icon-button" data-close-dialog aria-label="Close">×</button>
    </div>
    <label class="search-field">
        <x-icon name="search" />
        <input type="search" data-picker-query placeholder="Search your pantry and the catalog (empty: your whole pantry)" aria-label="Search your pantry and the catalog" enterkeyhint="search">
    </label>
    <form method="post" data-picker-form>
        @csrf
        @method('PATCH')
        <input type="hidden" name="action" value="product">
        <div class="picker-results" data-picker-results aria-live="polite"></div>
    </form>
    <div class="picker-other">
        @if ($newItemPage ?? false)
            {{-- In the import wizard a new item gets its own page, which comes back here. --}}
            <p class="picker-new-link">Not in the catalog? <a class="button secondary compact" href="#" data-picker-new-link><x-icon name="plus" />Add a new item</a></p>
        @else
            <form method="post" data-picker-form class="picker-new">
                @csrf
                @method('PATCH')
                <input type="hidden" name="action" value="new">
                <label>Not in the catalog? Add it as<input name="name" data-picker-new-name maxlength="120" required></label>
                <button class="button secondary compact"><x-icon name="plus" />New item</button>
            </form>
        @endif
        <form method="post" data-picker-form>
            @csrf
            @method('PATCH')
            <input type="hidden" name="action" value="skip" data-picker-skip-action>
            <button class="text-danger" data-picker-skip-label>Skip it. I always have this.</button>
        </form>
    </div>
</dialog>
