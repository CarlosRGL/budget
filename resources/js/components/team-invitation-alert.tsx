import { InfoIcon } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';
import { t } from '@/lib/i18n';

type Props = {
    invitation: TeamInvitationContext;
    action: 'Log in' | 'Register';
};

export default function TeamInvitationAlert({ invitation, action }: Props) {
    return (
        <Alert
            data-test="team-invitation-alert"
            className="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400"
        >
            <InfoIcon />
            <AlertDescription className="text-blue-900 dark:text-blue-100">
                {action === 'Log in'
                    ? t('Log in to join the household “:name”.', {
                          name: invitation.teamName,
                      })
                    : t('Create an account to join the household “:name”.', {
                          name: invitation.teamName,
                      })}
            </AlertDescription>
        </Alert>
    );
}
