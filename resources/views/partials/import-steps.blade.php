{{-- Where the import wizard is: 1 link, 2 ingredients, 3 save. --}}
<ol class="wizard-steps" aria-label="Import steps">
    @foreach (['Recipe link', 'Pick ingredients', 'Save'] as $index => $label)
        @php($number = $index + 1)
        <li class="{{ $number < $step ? 'done' : ($number === $step ? 'current' : '') }}" @if ($number === $step) aria-current="step" @endif>
            <span class="wizard-dot">@if ($number < $step)<x-icon name="check" />@else{{ $number }}@endif</span>
            <span>{{ $label }}</span>
        </li>
    @endforeach
</ol>
