<?php

namespace Modules\Tenancy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tenancy\Models\Invitation;
use Modules\Tenancy\Models\Organisation;

/**
 * The "you have been invited to join {workspace}" email.
 *
 * Queued so that inviting five colleagues does not make the person clicking
 * "invite" wait on five SMTP round trips — the request returns immediately and
 * the worker sends.
 *
 * The plaintext token is passed in as a constructor argument rather than read
 * off the invitation, because the invitation only ever stores its hash. That
 * does mean the token is serialised into the job payload; acceptable, and the
 * standard Laravel pattern for password resets, but it is the reason the queue
 * connection should be a trusted store (the DB queue here) and not a
 * third-party broker.
 */
class OrganisationInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $token,
        private readonly Organisation $organisation,
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
        $inviter = $this->invitation->invitedBy?->name;

        return (new MailMessage)
            ->subject("You have been invited to join {$this->organisation->name} on ".config('app.name'))
            ->greeting('Hello')
            ->line($inviter !== null
                ? "{$inviter} has invited you to join the {$this->organisation->name} workspace on ".config('app.name').'.'
                : "You have been invited to join the {$this->organisation->name} workspace on ".config('app.name').'.')
            ->line(config('app.name').' turns meeting transcripts into complete, professional minutes — decisions, action items, attendance and next steps.')
            ->action('Accept the invitation', route('tenancy.invitations.show', ['token' => $this->token]))
            ->line("This invitation expires on {$this->invitation->expires_at->toFormattedDayDateString()}.")
            ->line('If you were not expecting this, you can safely ignore this email.');
    }
}
