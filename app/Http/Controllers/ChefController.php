<?php

namespace App\Http\Controllers;

use App\Models\Chef;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        ]);
    }

    public function edit(Chef $chef)
    {
        return view('chefs.form', [
            'chef' => $chef,
            'photoRecipes' => $chef->recipes()->whereNotNull('image_path')->latest('id')->get(),
        ]);
    }

    public function update(Request $request, Chef $chef)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'site_name' => 'nullable|string|max:120',
            'website' => 'nullable|url:http,https|max:255',
            'bio' => 'nullable|string|max:5000',
            'hero_recipe_id' => ['nullable', Rule::exists('recipes', 'id')->where('chef_id', $chef->id)],
        ]);

        if ($data['name'] !== $chef->name) {
            $chef->slug = Chef::uniqueSlug($data['name'], $chef->id);
        }

        $chef->update($data);

        return redirect()->route('chefs.show', $chef)->with('success', "{$chef->name} saved.");
    }

    public function destroy(Request $request, Chef $chef)
    {
        abort_unless($request->user()->is_admin, 403);
        $chef->delete();

        return redirect()->route('chefs.index')->with('success', "{$chef->name} removed. Their recipes are kept without a chef.");
    }
}
