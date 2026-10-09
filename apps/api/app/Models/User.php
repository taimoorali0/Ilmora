<?php

namespace App\Models;

use App\Support\Abilities;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = ['school_id', 'name', 'phone', 'email', 'password', 'status', 'staff_id', 'family_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** Role keys, optionally limited to one branch (school-wide roles always count). */
    public function roleKeys(?string $branchId = null): array
    {
        return $this->roles
            ->filter(fn (UserRole $r) => $branchId === null || $r->branch_id === null || $r->branch_id === $branchId)
            ->pluck('role')->unique()->values()->all();
    }

    public function canDo(string $ability, ?string $branchId = null): bool
    {
        return $this->is_super_admin || Abilities::allows($this->roleKeys($branchId), $ability);
    }
}
