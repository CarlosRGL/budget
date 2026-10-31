<?php

namespace Tests\Feature\Console;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_member_and_the_household()
    {
        $this->artisan('budget:install', [
            '--name' => 'Alex',
            '--email' => 'alex@example.com',
            '--password' => 'secret-password',
            '--household' => 'Familia',
        ])->assertSuccessful();

        $user = User::sole();

        $this->assertSame('Alex', $user->name);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Familia', $user->currentTeam->name);
        $this->assertFalse($user->currentTeam->is_personal);
        $this->assertSame(TeamRole::Owner, $user->teamRole($user->currentTeam));
    }

    public function test_it_refuses_to_run_twice()
    {
        User::factory()->create();

        $this->artisan('budget:install', [
            '--name' => 'Alex',
            '--email' => 'alex@example.com',
            '--password' => 'secret-password',
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }
}
