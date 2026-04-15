<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;

/**
 * RoleController — admin only (enforced at route level via role:admin middleware).
 *
 * Fix: removed $this->authorize() calls — they require a RolePolicy to be registered
 *      which doesn't exist in this project. Access is controlled by the route-level
 *      role:admin middleware instead.
 */
class RoleController extends Controller
{
    /** GET /admin/roles */
    public function index()
    {
        return response()->json(Role::all());
    }

    /** GET /admin/roles/{id} */
    public function show($id)
    {
        return response()->json(Role::findOrFail($id));
    }

    /** POST /admin/roles */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|unique:roles,name',
            'description' => 'nullable|string|max:1000',
        ]);

        $role = Role::create($validated);

        return response()->json([
            'message' => 'Role created successfully.',
            'role'    => $role,
        ], 201);
    }

    /** PUT /admin/roles/{id} */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|unique:roles,name,' . $id,
            'description' => 'nullable|string|max:1000',
        ]);

        $role->update($validated);

        return response()->json([
            'message' => 'Role updated successfully.',
            'role'    => $role,
        ]);
    }

    /** DELETE /admin/roles/{id} */
    public function destroy($id)
    {
        $role = Role::findOrFail($id);

        // Prevent deleting core roles
        if (in_array($role->id, [1, 2, 3])) {
            return response()->json(['error' => 'Cannot delete a core role.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully.']);
    }
}
