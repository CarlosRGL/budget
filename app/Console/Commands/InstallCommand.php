<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'budget:install
        {--name= : Name of the first member}
        {--email= : Email of the first member}
        {--password= : Password of the first member}
        {--household=Familia : Name of the household}';

    protected $description = 'Create the household and its first member (registration is invite-only)';

    public function handle(CreateTeam $createTeam): int
    {
        if (User::query()->exists()) {
            $this->components->error('The app is already installed: invite new members from the household settings.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true, validate: ['email' => 'email']);
        $password = $this->option('password') ?: password('Password', required: true, validate: ['password' => 'min:8']);

        DB::transaction(function () use ($createTeam, $name, $email, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $createTeam->handle($user, $this->option('household'));
        });

        $this->components->info("{$name} can now log in at ".route('login'));

        return self::SUCCESS;
    }
}
