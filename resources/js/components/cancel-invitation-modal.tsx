import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Trans } from '@/components/trans';
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
import { destroy as destroyInvitation } from '@/routes/teams/invitations';
import type { Team, TeamInvitation } from '@/types';
import { t } from '@/lib/i18n';

type Props = {
    team: Team;
    invitation: TeamInvitation | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function CancelInvitationModal({
    team,
    invitation,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const cancelInvitation = () => {
        if (!invitation) {
            return;
        }

        router.visit(destroyInvitation([team.slug, invitation.code]), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Cancel invitation')}</DialogTitle>
                    <DialogDescription>
                        <Trans
                            text={t(
                                'Do you really want to cancel the invitation for :email?',
                            )}
                            values={{
                                email: <strong>{invitation?.email}</strong>,
                            }}
                        />
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">
                            {t('Keep invitation')}
                        </Button>
                    </DialogClose>

                    <Button
                        variant="destructive"
                        data-test="cancel-invitation-confirm"
                        disabled={processing}
                        onClick={cancelInvitation}
                    >
                        {t('Cancel invitation')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
