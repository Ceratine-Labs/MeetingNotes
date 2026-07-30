<?php

namespace Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset email for back-office accounts.
 *
 * A separate notification from the customer one because it must link to
 * `admin.password.reset`, not `password.reset`. The tokens are issued by different
 * brokers against different tables, so a staff token presented to the customer
 * reset form simply would not validate — and the resulting "invalid or expired
 * link" would be extremely confusing to debug.
 */
class AdminPasswordReset extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  Plaintext reset token. Only exists in this email; the
     *         broker stores a hash.
     * @param  string  $email  Included in the URL so the reset form can pre-fill it
     *         and the broker can look the token up.
     */
    public function __construct(
        private readonly string $token,
        private readonly string $email,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.admins.expire');

        return (new MailMessage)
            ->subject(config('app.name').' back office — reset your password')
            ->greeting('Password reset')
            ->line('A password reset was requested for your '.config('app.name').' back-office account.')
            ->action('Set a new password', route('admin.password.reset', [
                'token' => $this->token,
                'email' => $this->email,
            ]))
            ->line("This link expires in {$minutes} minutes.")
            // Stronger wording than the customer equivalent: an unexpected reset
            // request on a staff account is a security event, not a mistake to shrug
            // at.
            ->line('If you did not request this, someone may be attempting to access '
                .'the back office. Do not ignore it — check with the team.');
    }
}
