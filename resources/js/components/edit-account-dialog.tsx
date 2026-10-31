import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent, PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { update } from '@/routes/accounts';
import type { AccountDetails } from '@/types';

export default function EditAccountDialog({
    account,
    children,
}: PropsWithChildren<{ account: AccountDetails }>) {
    const [open, setOpen] = useState(false);
    const { currentTeam } = usePage().props;

    const form = useForm({
        name: account.name,
        note: account.note ?? '',
        on_budget: account.onBudget,
    });

    const changeOpen = (value: boolean) => {
        setOpen(value);

        if (value) {
            form.setData({
                name: account.name,
                note: account.note ?? '',
                on_budget: account.onBudget,
            });
            form.clearErrors();
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!currentTeam) {
            return;
        }

        form.patch(
            update({ current_team: currentTeam.slug, account: account.id }).url,
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-6">
                    <DialogHeader>
                        <DialogTitle>{t('Edit account')}</DialogTitle>
                        <DialogDescription>
                            {account.typeLabel}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="edit-account-name">{t('Name')}</Label>
                        <Input
                            id="edit-account-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="edit-account-note">{t('Note')}</Label>
                        <textarea
                            id="edit-account-note"
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                            rows={3}
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <InputError message={form.errors.note} />
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="edit-account-on-budget"
                            checked={form.data.on_budget}
                            disabled={!account.canChangeBudgetStatus}
                            onCheckedChange={(checked) =>
                                form.setData('on_budget', checked === true)
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="edit-account-on-budget">
                                {t('On budget account')}
                            </Label>
                            {!account.canChangeBudgetStatus && (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'This account already has transactions: its status can no longer change.',
                                    )}
                                </p>
                            )}
                        </div>
                    </div>
                    <InputError message={form.errors.on_budget} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
