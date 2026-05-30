<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('app.admin_email');
        $password = config('app.admin_password');
        $name     = config('app.admin_name', 'Admin');

        if (!$email || !$password) {
            $this->command->error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env');
            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($password),
                'is_admin' => true,
            ]
        );

        $this->command->info("Admin user ready: {$email}");
    }
}
