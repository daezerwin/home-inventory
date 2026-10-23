<?php

namespace App\Http\Controllers;

use App\Models\Chef;
use App\Models\Recipe;
use App\Services\ImageDownloader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChefController extends Controller
{
    public function index()
    {
        $chefs = Chef::withCount('recipes')
            ->with(['recipes' => fn ($q) => $q->whereNotNull('image_path')->latest('id')])
            ->orderBy('name')
            ->get();

        return view('chefs.index', [
            'chefs' => $chefs,
            'heroImage' => Recipe::whereNotNull('image_path')->latest('id')->first()?->image,
            'recipeCount' => Recipe::count(),
        ]);
    }

    public function show(Chef $chef)
    {
        return view('chefs.show', [
            'chef' => $chef,
            'recipes' => $chef->recipes()->withCount('ingredients')->latest('id')->paginate(18),
            'heroImage' => $chef->heroImage(),
            'photoRecipes' => $chef->recipes()->whereNotNull('image_path')->latest('id')->get(),
        ]);
    }

    public function edit(Chef $chef)
    {
        return view('chefs.form', [
            'chef' => $chef,
            'photoRecipes' => $chef->recipes()->whereNotNull('image_path')->latest('id')->get(),
        ]);
    }

    public function update(Request $request, Chef $chef, ImageDownloader $images)
    {
        // "photo" keeps the uploaded photo; anything else is a recipe id, or empty for the newest recipe.
        $keepPhoto = $request->input('hero_recipe_id') === 'photo';

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'site_name' => 'nullable|string|max:120',
            'website' => 'nullable|url:http,https|max:255',
            'bio' => 'nullable|string|max:5000',
            'hero_recipe_id' => [Rule::excludeIf($keepPhoto), 'nullable', Rule::exists('recipes', 'id')->where('chef_id', $chef->id)],
            'hero_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:12288',
        ], [
            'hero_photo.max' => 'The page photo can be at most 12 MB.',
        ]);
        unset($data['hero_photo']);

        if ($data['name'] !== $chef->name) {
            $chef->slug = Chef::uniqueSlug($data['name'], $chef->id);
        }

        $oldPhoto = $chef->hero_image_path;

        if ($file = $request->file('hero_photo')) {
            try {
                $chef->hero_image_path = $images->storeFile($file->getRealPath(), 'chefs', config('inventory.hero_image_max_size'), hash_file('sha256', $file->getRealPath()));
            } catch (RuntimeException) {
                throw ValidationException::withMessages(['hero_photo' => "That photo couldn't be read. Try a JPG, PNG or WebP."]);
            }
            $data['hero_recipe_id'] = null;
        } elseif ($keepPhoto) {
            $data['hero_recipe_id'] = null;
        } elseif ($request->has('hero_recipe_id')) {
            $chef->hero_image_path = null;
        }

        $chef->update($data);

        if ($oldPhoto && $oldPhoto !== $chef->hero_image_path) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return redirect()->route('chefs.show', $chef)->with('success', "{$chef->name} saved.");
    }

    public function destroy(Request $request, Chef $chef)
    {
        abort_unless($request->user()->is_admin, 403);

        if ($chef->hero_image_path) {
            Storage::disk('public')->delete($chef->hero_image_path);
        }

        $chef->delete();

        return redirect()->route('chefs.index')->with('success', "{$chef->name} removed. Their recipes are kept without a chef.");
    }
}
