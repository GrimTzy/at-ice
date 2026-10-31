<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',      // admin | hr | employee | driver | customer
        'sub_role',  // checker | salesman | maintenance | null
        'must_change_password', // new customer accounts: change the one-time password on first login
        'is_active', // false: login refused (deactivated employee, removed customer)
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
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ROLE HELPERS
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isHR(): bool
    {
        return $this->role === 'hr';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function isDriver(): bool
    {
        return $this->role === 'driver';
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    // SUB_ROLE HELPERS (Employee only)
    public function isChecker(): bool
    {
        return $this->role === 'employee' && $this->sub_role === 'checker';
    }

    public function isSalesman(): bool
    {
        return $this->role === 'employee' && $this->sub_role === 'salesman';
    }

    public function isMaintenance(): bool
    {
        return $this->role === 'employee' && $this->sub_role === 'maintenance';
    }

    // Generic sub_role check — useful for future sub_roles
    public function hasSubRole(string $subRole): bool
    {
        return $this->role === 'employee' && $this->sub_role === $subRole;
    }

    // True when this account must not be used: the login itself is disabled,
    // or it belongs to an employee HR has deactivated. The second case covers
    // accounts deactivated before deactivation also disabled the login (their
    // users.is_active is still true). A NULL is_active (older seeded accounts)
    // counts as active.
    public function isDeactivated(): bool
    {
        if ($this->is_active === false) {
            return true;
        }
        if (in_array($this->role, ['employee', 'driver'], true)) {
            $employee = $this->employee()->first(['id', 'is_active']);

            return $employee !== null && ! $employee->is_active;
        }

        return false;
    }

    // RELATIONSHIPS
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }
}
