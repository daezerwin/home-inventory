@php($time = \App\Models\Recipe::formatMinutes($recipe->totalTime()))

<a class="recipe-card" href="{{ route('recipes.show', $recipe) }}">
    <div class="recipe-photo">
        @if ($recipe->image)
            <img src="{{ $recipe->image }}" alt="" loading="lazy" decoding="async">
        @else
            <div class="photo-placeholder"><x-icon name="book" /><span>Recipe</span></div>
        @endif
    </div>
    <div class="recipe-info">
        @if (($showChef ?? true) && $recipe->chef)
            <span class="product-category">{{ $recipe->chef->name }}</span>
        @endif
        <h3>{{ $recipe->title }}</h3>
        <p class="recipe-meta">
            @if ($time)
                <span><x-icon name="clock" />{{ $time }}</span>
            @endif
            <span><x-icon name="box" />{{ $recipe->ingredients_count }} {{ str('ingredient')->plural($recipe->ingredients_count) }}</span>
        </p>
    </div>
</a>
