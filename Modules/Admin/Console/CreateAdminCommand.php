<?php

namespace Modules\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Modules\Admin\Models\AdminUser;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates a back-office account.
 *
 * The only way to add staff — there is deliberately no self-registration for the back
 * office, and no "invite an admin" flow in the UI. With two staff members, adding an
 * account is a shell command run by someone who already has server access, and that
 * is a smaller attack surface than any web flow could be.
 *
 * Also the recovery path when nobody can sign in: with server access, a new account
 * can always be created.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
                            {--name= : Full name}
                            {--email= : Email address}
                            {--password= : Password (omit to be prompted, or to have one generated)}';

    protected $description = 'Create a back-office (staff) account';

    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Full name',
            required: true,
        );

        $email = mb_strtolower(trim($this->option('email') ?: text(
            label: 'Email address',
            required: true,
        )));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("[{$email}] is not a valid email address.");

            return self::FAILURE;
        }

        if (AdminUser::withTrashed()->where('email', $email)->exists()) {
            // withTrashed: the email column is uniquely indexed and a soft-deleted row
            // still occupies it, so a plain existence check would let the insert fail
            // with a confusing constraint error instead.
            $this->error("A back-office account already exists for {$email} (possibly deactivated).");

            return self::FAILURE;
        }

        $password = $this->option('password');
        $generated = false;

        if ($password === null && $this->input->isInteractive()) {
            // Prompted rather than passed as an option where possible: a password in
            // an argument ends up in the shell history and in the process list.
            $password = promptPassword(label: 'Password (leave blank to generate one)');
        }

        if (empty($password)) {
            $password = Str::password(20);
            $generated = true;
        }

        $validator = validator(
            ['password' => $password],
            ['password' => [PasswordRule::min(12)->letters()->numbers()->symbols()]]
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $admin = AdminUser::query()->create([
            'name' => $name,
            'email' => $email,
            // Hashed by the model's `password` => 'hashed' cast.
            'password' => $password,
        ]);

        $this->newLine();
        $this->info("Back-office account created for {$admin->email}.");

        if ($generated) {
            // Printed exactly once and never stored in plaintext anywhere.
            $this->newLine();
            $this->warn("Generated password: {$password}");
            $this->warn('Save it now — it is not recoverable and will not be shown again.');
        }

        $this->newLine();
        $this->line('Sign in at '.route('admin.login'));

        return self::SUCCESS;
    }
}
