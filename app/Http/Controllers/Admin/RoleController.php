<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        return view('admin.roles.form', [
            'role' => new Role(),
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'selectedPermissions' => [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Rol creado.');
    }

    public function edit(Role $role)
    {
        return view('admin.roles.form', [
            'role' => $role,
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'selectedPermissions' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validated($request, $role);

        $role->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if (! $role->is_system) {
            $role->slug = $data['slug'];
        }

        $role->save();
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Rol actualizado.');
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return back()->withErrors(['role' => 'No se puede eliminar un rol de sistema.']);
        }

        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'El rol tiene usuarios asignados.']);
        }

        $role->delete();

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Rol eliminado.');
    }

    protected function validated(Request $request, ?Role $role = null): array
    {
        $slug = $request->input('slug') ?: Str::slug((string) $request->input('name'));

        $request->merge(['slug' => $slug]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:120',
                Rule::unique('roles', 'slug')->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }
}
