{{-- Deletes the chef. With recipes, the confirm also offers deleting those (data-confirm-deny in app.js);
     without JavaScript the plain confirm keeps them. --}}
@php($count = $chef->recipes()->count())
@php($noun = $count === 1 ? 'recipe' : "{$count} recipes")
<form class="{{ $class ?? 'danger-zone' }}" method="post" action="{{ route('chefs.destroy', $chef) }}"
      data-confirm="Delete {{ $chef->name }}? {{ $count ? "Keep their {$noun} without a chef, or delete ".($count === 1 ? 'it' : 'them').' too.' : 'They have no recipes.' }}"
      data-confirm-button="{{ $count ? 'Delete chef, keep '.($count === 1 ? 'recipe' : 'recipes') : 'Delete chef' }}"
      @if ($count) data-confirm-deny="Delete chef and {{ $count === 1 ? 'recipe' : $noun }}" data-confirm-deny-field="with_recipes" @endif>
    @csrf
    @method('DELETE')
    <input type="hidden" name="with_recipes" value="0">
    <button class="{{ $buttonClass ?? 'button secondary danger' }}"><x-icon name="trash" />Delete chef</button>
</form>
