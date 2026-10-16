@props(['image' => null])

{{-- Full-width hero whose photo drifts slower than the page (see "Parallax" in app.js). --}}
<section {{ $attributes->merge(['class' => 'parallax-hero']) }}>
    <div class="parallax-bg {{ $image ? '' : 'no-image' }}" data-parallax @if ($image) style="background-image: url('{{ $image }}')" @endif></div>
    <div class="parallax-shade"></div>
    <div class="parallax-content">
        {{ $slot }}
    </div>
</section>
