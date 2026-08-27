<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_superuser',
        'is_active',
        'must_change_password',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superuser' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user');
    }

    public function isSuperuser(): bool
    {
        return (bool) $this->is_superuser;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function hasRole(string $slug): bool
    {
        if ($this->isSuperuser()) {
            return true;
        }

        return $this->roles->contains('slug', $slug);
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperuser()) {
            return true;
        }

        if ($this->permissions->contains('slug', $slug)) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->permissions->contains('slug', $slug)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }

        return false;
    }

    public function allPermissionSlugs(): Collection
    {
        if ($this->isSuperuser()) {
            return Permission::query()->pluck('slug');
        }

        $fromRoles = $this->roles->loadMissing('permissions')
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'));

        $direct = $this->permissions->pluck('slug');

        return $fromRoles->merge($direct)->unique()->values();
    }

    public function syncRoles(array $roleIds): void
    {
        $this->roles()->sync($roleIds);
    }

    public function syncDirectPermissions(array $permissionIds): void
    {
        $this->permissions()->sync($permissionIds);
    }

    public function markLogin(?string $ip = null): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
    }
}
