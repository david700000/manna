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
        // Only create the root user if it doesn't already exist.
        // Do NOT use updateOrCreate here — that would reset the password
        // and must_change_password flag on every deploy.
        $rootExists = User::where('email', 'david07israel@gmail.com')->exists();
        if (!$rootExists) {
            User::create([
                'email'               => 'david07israel@gmail.com',
                'name'                => 'Root Admin',
                'password'            => 'admin',
                'role'                => 'root',
                'must_change_password' => true,
            ]);
        }
        
        // Example default settings
        \App\Models\Setting::updateOrCreate(
            ['key' => 'theme_colors'],
            ['value' => json_encode(['primary' => '#F47B20', 'secondary' => '#1A1A2E'])]
        );
    }
}
