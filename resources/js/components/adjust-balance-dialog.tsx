import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent, PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { moneyToInput, parseMoney } from '@/lib/money';
import { balance as balanceRoute } from '@/routes/accounts';
import type { AccountDetails } from '@/types';

export default function AdjustBalanceDialog({
    account,
    children,
}: PropsWithChildren<{ account: AccountDetails }>) {
    const [open, setOpen] = useState(false);
    const { currentTeam } = usePage().props;
    // A synced account is reconciled with what the bank says.
    const target = account.bankBalance ?? account.balance;
    const form = useForm({ balance: moneyToInput(target) });
    const parsed = parseMoney(form.data.balance);

    const changeOpen = (value: boolean) => {
        setOpen(value);

        if (value) {
            form.setData('balance', moneyToInput(target));
            form.clearErrors();
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (parsed === null || !currentTeam) {
            form.setError('balance', t('Invalid amount, for example 1,234.56'));

            return;
        }

        form.transform(() => ({ balance: parsed }));
        form.post(
            balanceRoute({
                current_team: currentTeam.slug,
                account: account.id,
            }).url,
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-6">
                    <DialogHeader>
                        <DialogTitle>
                            {account.onBudget
                                ? t('Reconcile the account')
                                : t('Update the balance')}
                        </DialogTitle>
                        <DialogDescription>
                            {account.onBudget
                                ? t(
                                      'A “Balance adjustment” transaction records the difference in Ready to Assign. First look for a forgotten or duplicate transaction: the adjustment is the last resort.',
                                  )
                                : t(
                                      'A “Balance adjustment” transaction records the difference.',
                                  )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="adjust-balance">
                            {account.bankBalance !== null
                                ? t('Balance at the bank')
                                : t('New balance')}
                        </Label>
                        <Input
                            id="adjust-balance"
                            value={form.data.balance}
                            onChange={(event) =>
                                form.setData('balance', event.target.value)
                            }
                            inputMode="decimal"
                            className="text-right tabular-nums"
                            autoFocus
                        />
                        <InputError message={form.errors.balance} />
                        {parsed !== null && parsed !== account.balance && (
                            <p className="text-sm text-muted-foreground">
                                {t('Adjustment:')}{' '}
                                <Money
                                    cents={parsed - account.balance}
                                    positive
                                />
                            </p>
                        )}
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {account.onBudget ? t('Reconcile') : t('Update')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
