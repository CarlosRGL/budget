<?php

namespace App\Notifications\Teams;

use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to join a household. Sent right away (no queue worker needed),
 * with a link that opens the registration form, or the login form when the
 * person already has an account.
 */
class TeamInvitation extends Notification
{
    use Queueable;

    public function __construct(public TeamInvitationModel $invitation)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $team = $this->invitation->team;
        $hasAccount = User::query()->whereRaw('LOWER(email) = ?', [strtolower($this->invitation->email)])->exists();

        return (new MailMessage)
            ->subject(__(':inviter invites you to the “:household” budget', ['inviter' => $this->invitation->inviter->name, 'household' => $team->name]))
            ->greeting(__('Hello,'))
            ->line(__(':inviter invites you to share the “:household” household budget: accounts, envelopes and transactions, with the same rights.', [
                'inviter' => $this->invitation->inviter->name,
                'household' => $team->name,
            ]))
            ->action(
                $hasAccount ? __('Log in and join the household') : __('Create my access'),
                self::url($this->invitation, $hasAccount),
            )
            ->line(__('This link is valid until :date.', ['date' => (string) $this->invitation->expires_at?->translatedFormat('j F Y')]))
            ->salutation(__('See you soon,'));
    }

    /**
     * The link of the invitation, also shown on the household page to share it by hand.
     */
    public static function url(TeamInvitationModel $invitation, bool $hasAccount = false): string
    {
        return route($hasAccount ? 'login' : 'register', ['invitation' => $invitation->code]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'team_id' => $this->invitation->team_id,
            'team_name' => $this->invitation->team->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
