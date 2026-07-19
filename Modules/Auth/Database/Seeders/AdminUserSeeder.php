<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

/**
 * Seeds the initial admin account. Password comes from
 * ADMIN_SEED_PASSWORD in .env when set; otherwise a random one is
 * generated and printed ONCE to the console — change it after first
 * login either way.
 */
class AdminUserSeeder extends Seeder
{
    public $order = 10;

    public function run(): void
    {
        if (User::query()->where('email', 'admin@meetingnotes.local')->exists()) {
            return;
        }

        $password = config('core.admin_seed_password') ?: Str::password(16);

        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@meetingnotes.local',
            'password' => $password,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->command?->warn("Admin user: admin@meetingnotes.local  password: {$password}");
        $this->command?->warn('Change this password after first login.');
    }
}
