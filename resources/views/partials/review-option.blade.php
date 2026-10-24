{{-- One pickable product in the import wizard; pantry items show how many are at home instead of the price. --}}
@php($chosen = $product?->id === $option->id)
@php($home = $atHome->get($option->id))
<button class="option-card {{ $chosen ? 'selected' : '' }} {{ $home !== null ? 'in-pantry' : '' }}" name="product_id" value="{{ $option->id }}" aria-pressed="{{ $chosen ? 'true' : 'false' }}">
    <span class="thumb">
        @if ($option->image)
            <img src="{{ $option->image }}" alt="" loading="lazy" decoding="async">
        @else
            <x-icon name="box" />
        @endif
    </span>
    <span class="option-text">
        <b>{{ $option->name }}</b>
        <small>
            @if ($home !== null)
                {{ collect([$option->size, $home ? "At home: {$home}" : 'None at home'])->filter()->implode(' · ') }}
            @else
                {{ collect([$option->size, $option->price > 0 ? '₱'.number_format($option->price, 2) : null])->filter()->implode(' · ') ?: $option->category }}
            @endif
        </small>
    </span>
    @if ($chosen)
        <x-icon name="check" class="option-check" />
    @endif
</button>
