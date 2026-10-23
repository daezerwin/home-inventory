{{-- Chef details and page photo; used by the editor on the chef page and the stand-alone edit page. --}}
@php($hero = old('hero_recipe_id', $chef->hero_image_path ? 'photo' : $chef->hero_recipe_id))

<div class="form-columns">
    <label>Name<input name="name" value="{{ old('name', $chef->name) }}" required maxlength="120"></label>
    <label>Site or kitchen<input name="site_name" value="{{ old('site_name', $chef->site_name) }}" maxlength="120" placeholder="e.g. Panlasang Pinoy"></label>
</div>
<label>Website<input type="url" name="website" value="{{ old('website', $chef->website) }}" maxlength="255" placeholder="https://…"></label>
<label>Bio <small class="muted">Where they cook, what they're known for. A blank line starts a new paragraph.</small>
    <textarea name="bio" rows="6" maxlength="5000">{{ old('bio', $chef->bio) }}</textarea>
</label>

<fieldset class="hero-picker" data-hero-picker>
    <legend>Page photo</legend>
    <label class="hero-option" data-hero-photo @if (! $chef->hero_photo) hidden @endif>
        <input type="radio" name="hero_recipe_id" value="photo" @checked($hero === 'photo')>
        <img @if ($chef->hero_photo) src="{{ $chef->hero_photo }}" @endif data-saved="{{ $chef->hero_photo }}" alt="Your photo" loading="lazy" decoding="async">
        <span class="hero-option-label">Your photo</span>
    </label>
    <label class="hero-option">
        <input type="radio" name="hero_recipe_id" value="" @checked(! $hero)>
        <span class="hero-option-auto">Newest recipe</span>
    </label>
    @foreach ($photoRecipes as $recipe)
        <label class="hero-option">
            <input type="radio" name="hero_recipe_id" value="{{ $recipe->id }}" @checked($hero == $recipe->id)>
            <img src="{{ $recipe->image }}" alt="{{ $recipe->title }}" loading="lazy" decoding="async">
        </label>
    @endforeach
    <label class="hero-option hero-upload">
        <input type="file" name="hero_photo" accept="image/jpeg,image/png,image/webp" data-hero-upload>
        <span class="hero-option-auto"><x-icon name="upload" />Upload photo</span>
    </label>
    <p class="muted">JPG, PNG or WebP up to 12 MB. Choosing a recipe photo removes an uploaded one.</p>
</fieldset>
