<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// An employee HR has deactivated cannot sign in or keep using an old token,
// even when the login row itself was left active (accounts deactivated
// before deactivation also disabled the login, e.g. checker2@atice.cloud).
class DeactivatedAccountTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $email, bool $userActive, bool $employeeActive, string $role = 'employee', ?string $sub = 'checker'): User
    {
        $user = User::create(['name' => 'Carlo Jayco', 'email' => $email, 'password' => 'Secret123!', 'role' => $role, 'sub_role' => $sub, 'is_active' => $userActive]);
        Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT), 'first_name' => 'Carlo', 'last_name' => 'Jayco',
            'position' => $role === 'driver' ? 'Driver' : 'Checker', 'date_hired' => '2026-10-02', 'is_active' => $employeeActive,
            'deactivated_at' => $employeeActive ? null : now(),
        ]);

        return $user;
    }

    private function me(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/me');
    }

    public function test_a_deactivated_employee_with_an_active_login_row_cannot_sign_in(): void
    {
        $this->employee('checker2@atice.cloud', userActive: true, employeeActive: false);

        $this->postJson('/api/login', ['email' => 'checker2@atice.cloud', 'password' => 'Secret123!'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This account has been deactivated. Please contact HR or the Admin.');
    }

    public function test_a_wrong_password_still_gets_the_normal_error_for_a_deactivated_employee(): void
    {
        $this->employee('checker2@atice.cloud', userActive: true, employeeActive: false);

        $this->postJson('/api/login', ['email' => 'checker2@atice.cloud', 'password' => 'Wrong123!'])
            ->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_an_old_token_stops_working_and_is_revoked(): void
    {
        $user = $this->employee('checker2@atice.cloud', userActive: true, employeeActive: true);
        $token = $user->createToken('auth_token')->plainTextToken;
        $this->me($token)->assertOk();

        // Deactivated directly in the database, the way older code left it:
        // employee inactive, login row still active, token not revoked.
        Employee::where('user_id', $user->id)->update(['is_active' => false, 'deactivated_at' => now()]);

        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_disabled_login_row_also_blocks_an_old_token(): void
    {
        $user = $this->employee('driver@atice.cloud', userActive: true, employeeActive: true, role: 'driver', sub: null);
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_active_accounts_are_not_affected(): void
    {
        $this->employee('checker3@atice.cloud', userActive: true, employeeActive: true);
        $hr = User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'Secret123!', 'role' => 'hr']);

        $token = $this->postJson('/api/login', ['email' => 'checker3@atice.cloud', 'password' => 'Secret123!'])->assertOk()->json('token');
        $this->me($token)->assertOk()->assertJsonPath('email', 'checker3@atice.cloud');
        $this->assertFalse($hr->fresh()->isDeactivated());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'hr@atice.cloud', 'password' => 'Secret123!'])->assertOk();
    }

    public function test_reactivation_by_hr_lets_the_employee_sign_in_again(): void
    {
        $user = $this->employee('checker2@atice.cloud', userActive: true, employeeActive: false);
        $hr = User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'Secret123!', 'role' => 'hr']);
        $hrToken = $hr->createToken('auth_token')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($hrToken)->postJson('/api/employees/'.$user->employee->id.'/reactivate')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'checker2@atice.cloud', 'password' => 'Secret123!'])->assertOk();
    }
}
