<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->with('roles')
            ->orderByDesc('is_superuser')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::orderBy('name')->get(),
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'selectedRoles' => [],
            'selectedPermissions' => [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'is_active' => $request->boolean('is_active', true),
            'is_superuser' => false,
            'must_change_password' => $request->boolean('must_change_password', true),
        ]);

        $user->syncRoles($data['roles'] ?? []);
        $user->syncDirectPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Administrador creado.');
    }

    public function edit(User $user)
    {
        $user->load(['roles', 'permissions']);

        return view('admin.users.form', [
            'user' => $user,
            'roles' => Role::orderBy('name')->get(),
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'selectedRoles' => $user->roles->pluck('id')->all(),
            'selectedPermissions' => $user->permissions->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        if ($user->is_superuser && ! $request->user()->isSuperuser()) {
            abort(403, 'Solo un superusuario puede editar a otro superusuario.');
        }

        $data = $this->validated($request, $user);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
            $user->must_change_password = $request->boolean('must_change_password');
        } else {
            $user->must_change_password = $request->boolean('must_change_password');
        }

        // Nunca permitir quitar el flag de superusuario desde este formulario
        // salvo que el actor sea superusuario y no se esté auto-bloqueando.
        if ($request->user()->isSuperuser() && $request->has('is_superuser')) {
            if ($user->id === $request->user()->id && ! $request->boolean('is_superuser')) {
                return back()->withErrors(['is_superuser' => 'No puedes quitarte el rol de superusuario a ti mismo.']);
            }
            $user->is_superuser = $request->boolean('is_superuser');
        }

        $user->save();

        if (! $user->is_superuser) {
            $user->syncRoles($data['roles'] ?? []);
            $user->syncDirectPermissions($data['permissions'] ?? []);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Administrador actualizado.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'No puedes eliminarte a ti mismo.']);
        }

        if ($user->is_superuser) {
            return back()->withErrors(['user' => 'No se puede eliminar un superusuario.']);
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Administrador eliminado.');
    }

    protected function validated(Request $request, ?User $user = null): array
    {
        $passwordRules = $user
            ? ['nullable', 'confirmed', Password::min(8)->letters()->numbers()]
            : ['required', 'confirmed', Password::min(8)->letters()->numbers()];

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => $passwordRules,
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }
}
