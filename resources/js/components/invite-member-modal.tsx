import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as storeInvitation } from '@/routes/teams/invitations';
import type { RoleOption, Team } from '@/types';
import { t } from '@/lib/i18n';

type Props = {
    team: Team;
    /** Kept for the kit's callers: households invite everyone with the same access. */
    availableRoles?: RoleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function InviteMemberModal({ team, open, onOpenChange }: Props) {
    const handleOpenChange = (nextOpen: boolean) => onOpenChange(nextOpen);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...storeInvitation.form(team.slug)}
                    className="space-y-6"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{t('Invite member')}</DialogTitle>
                                <DialogDescription>
                                    {t(
                                        'Send an invitation to join this household.',
                                    )}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {t('Email address')}
                                    </Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        data-test="invite-email"
                                        placeholder={t('name@example.com')}
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                {/* Everyone in a household has the same access to the budget. */}
                                <input
                                    type="hidden"
                                    name="role"
                                    value="admin"
                                />
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'The invited person will have the same access to the budget as you. The link is valid for 7 days.',
                                    )}
                                </p>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">
                                        {t('Cancel')}
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="invite-submit"
                                    disabled={processing}
                                >
                                    {t('Send invitation')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
