<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /** Creates the first owner account from ADMIN_* values in .env (idempotent). */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');
        if (! $password) {
            throw new RuntimeException('Set ADMIN_PASSWORD in .env before seeding.');
        }

        $username = mb_strtolower(env('ADMIN_USERNAME', 'admin'));

        User::updateOrCreate(
            ['username' => $username],
            [
                'name' => env('ADMIN_NAME', 'Owner'),
                'email' => env('ADMIN_EMAIL') ? mb_strtolower(env('ADMIN_EMAIL')) : null,
                'mobile' => User::normaliseMobile(env('ADMIN_MOBILE')),
                'role' => User::ROLE_OWNER,
                'is_active' => true,
                'password' => $password,
            ],
        );
    }
}
