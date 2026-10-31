<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\FirstMember;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (FirstMember::isExpected()) {
            return $this->createFirstMember($input);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'email' => [...$this->emailRules(), $this->invitedEmailRule()],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'locale' => app()->getLocale(),
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->joinInvitedHousehold($user);

            return $user;
        });
    }

    /**
     * On a fresh install, the first person to register needs no invitation:
     * they open a new household as its owner, already verified.
     *
     * @param  array<string, string>  $input
     */
    private function createFirstMember(array $input): User
    {
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'household' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($input, $validated) {
            // Someone else may have registered in the meantime.
            abort_unless(FirstMember::isExpected(), 403, __('No pending invitation for this email address.'));

            $user = User::create([
                'locale' => app()->getLocale(),
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $household = trim((string) ($validated['household'] ?? ''));

            $this->createTeam->handle($user, $household !== '' ? $household : __('My household'));

            return $user;
        });
    }

    /**
     * The invited person joins the household straight away: no personal household
     * of their own, and no verification email since the invitation link reached them.
     */
    private function joinInvitedHousehold(User $user): void
    {
        $invitation = TeamInvitation::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->pending()
            ->latest()
            ->firstOrFail();

        $invitation->team->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['role' => $invitation->role],
        );
        $invitation->update(['accepted_at' => now()]);

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->switchTeam($invitation->team);
    }

    /**
     * Registration is invite-only: the email must match a pending team invitation.
     */
    private function invitedEmailRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $invited = TeamInvitation::query()
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $value)])
                ->pending()
                ->exists();

            if (! $invited) {
                $fail(__('No pending invitation for this email address.'));
            }
        };
    }
}
