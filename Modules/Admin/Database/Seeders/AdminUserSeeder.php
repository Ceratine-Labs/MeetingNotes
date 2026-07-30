<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Admin\Models\AdminUser;

/**
 * Seeds the first back-office account.
 *
 * The password comes from ADMIN_SEED_PASSWORD when set; otherwise a random one is
 * generated and printed to the console exactly once. There is deliberately no
 * hardcoded default: a known admin password committed to a repository is a
 * standing invitation, and having no fallback means there is nothing to forget to
 * change.
 *
 * Runs once through the seed master. To add further staff, use
 * `php artisan admin:create` — that is the supported path, not editing this file.
 */
class AdminUserSeeder extends Seeder
{
    /** @var int Seed-master ordering within the module (lower runs first). */
    public $order = 10;

    public function run(): void
    {
        $email = mb_strtolower((string) config('admin.seed.email'));

        // withTrashed: the email column is uniquely indexed and a soft-deleted row
        // still occupies it, so a plain exists() check would let the insert fail on
        // the constraint instead of skipping cleanly.
        if (AdminUser::withTrashed()->where('email', $email)->exists()) {
            $this->command?->line("Back-office account {$email} already exists — skipping.");

            return;
        }

        $password = config('admin.seed.password') ?: Str::password(20);
        $generated = empty(config('admin.seed.password'));

        AdminUser::query()->create([
            'name' => config('admin.seed.name'),
            'email' => $email,
            // Hashed by the model's `password` => 'hashed' cast.
            'password' => $password,
        ]);

        $this->command?->newLine();
        $this->command?->warn("Back-office account created: {$email}");

        if ($generated) {
            $this->command?->warn("Password: {$password}");
            $this->command?->warn('This is shown once and is not recoverable. Save it now.');
        }

        $this->command?->warn('Sign in at /admin/login — separate from the customer login.');
    }
}
