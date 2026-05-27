<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'yefersonbolano25@gmail.com'],
            [
                'name'     => 'Yeferson',
                'password' => Hash::make('Draker2505'),
            ]
        );

        UserSetting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'memory_enabled'   => true,
                'auto_title'       => true,
                'stream_responses' => true,
                'language'         => 'es',
                'timezone'         => 'America/Bogota',
            ]
        );
    }
}
