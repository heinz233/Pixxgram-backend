<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    // Public: list all categories
    public function index()
    {
        return response()->json(Category::all());
    }

    // Public: show single category
    public function show($id)
    {
        return response()->json(Category::findOrFail($id));
    }

    // Admin: create — handled via AdminController::manageCategories()
    // These methods remain for direct route use if needed

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|unique:categories,name',
            'slug'        => 'nullable|string|unique:categories,slug',
            'description' => 'nullable|string|max:1000',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        $category = Category::create($validated);

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|unique:categories,name,' . $id,
            'slug'        => 'sometimes|string|unique:categories,slug,' . $id,
            'description' => 'nullable|string|max:1000',
        ]);

        // Auto-regenerate slug when name changes and no explicit slug supplied
        if (isset($validated['name']) && !isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        return response()->json([
            'message'  => 'Category updated successfully.',
            'category' => $category,
        ]);
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }
}
