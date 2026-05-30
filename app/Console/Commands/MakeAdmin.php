<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeAdmin extends Command
{
    protected $signature   = 'app:make-admin {email? : The email address of the user to promote (defaults to ADMIN_EMAIL from .env)}';
    protected $description = 'Grant admin privileges to a user by email';

    public function handle(): int
    {
        $email = $this->argument('email') ?? config('app.admin_email');

        if (!$email) {
            $this->error('Provide an email or set ADMIN_EMAIL in .env');
            return self::FAILURE;
        }

        $password = $this->ask('Password (leave empty to use ADMIN_PASSWORD from .env)');
        if (empty($password)) {
            $password = config('app.admin_password');
        }

        if (!$password) {
            $this->error('Password required');
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => 'Admin',
                'password' => Hash::make($password),
                'is_admin' => true,
            ]
        );

        $this->info("Admin: {$user->email} (is_admin=true)");

        return self::SUCCESS;
    }
}
