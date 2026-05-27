<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'yefersonbolano25@gmail.com'],
            [
                'name'     => 'Yeferson',
                'password' => Hash::make('Draker2505'),
            ]
        );
    }
}
