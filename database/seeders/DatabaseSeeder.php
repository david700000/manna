<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'david07israel@gmail.com'],
            [
                'name' => 'Root Admin',
                'password' => \Illuminate\Support\Facades\Hash::make('admin'),
                'role' => 'root',
                'must_change_password' => true,
            ]
        );
        
        // Example default settings
        \App\Models\Setting::updateOrCreate(
            ['key' => 'theme_colors'],
            ['value' => json_encode(['primary' => '#F47B20', 'secondary' => '#1A1A2E'])]
        );
    }
}
