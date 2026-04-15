<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct()
    {
        // index is public (no auth). All mutations require admin.
        $this->middleware('auth:sanctum')->except(['index']);
        $this->middleware('role:admin')->except(['index']);
    }

    /** GET /locations — public listing (active only) */
    public function index()
    {
        return response()->json(Location::active()->get());
    }

    /** POST /admin/locations */
    public function store(Request $request)
    {
        $request->validate([
            'name'   => 'required|string|unique:locations,name',
            'region' => 'nullable|string',
        ]);

        $location = Location::create($request->only(['name', 'region']));

        return response()->json([
            'message'  => 'Location created successfully.',
            'location' => $location,
        ], 201);
    }

    /** PUT /admin/locations/{id} */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name'      => 'sometimes|string|unique:locations,name,' . $id,
            'region'    => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $location = Location::findOrFail($id);
        $location->update($request->only(['name', 'region', 'is_active']));

        return response()->json([
            'message'  => 'Location updated successfully.',
            'location' => $location,
        ]);
    }

    /** DELETE /admin/locations/{id} */
    public function destroy($id)
    {
        $location = Location::findOrFail($id);
        $location->delete();

        return response()->json(['message' => 'Location deleted successfully.']);
    }
}
