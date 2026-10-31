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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { todayIso } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { moneyToInput, parseMoney } from '@/lib/money';
import { store } from '@/routes/accounts';
import type { AccountTypeValue } from '@/types';

type CreateAccountForm = {
    name: string;
    type: AccountTypeValue;
    on_budget: boolean;
    balance: string;
    starting_date: string;
};

/**
 * `stay`: the account is added without leaving the page (onboarding), instead
 * of opening it.
 */
export default function CreateAccountDialog({
    children,
    stay = false,
}: PropsWithChildren<{ stay?: boolean }>) {
    const [open, setOpen] = useState(false);
    const { currentTeam, sidebarAccounts } = usePage().props;
    const types = sidebarAccounts?.types ?? [];

    const form = useForm<CreateAccountForm>({
        name: '',
        type: 'checking',
        on_budget: true,
        balance: '',
        starting_date: todayIso(),
    });

    const changeOpen = (value: boolean) => {
        setOpen(value);

        if (!value) {
            form.reset();
            form.clearErrors();
        }
    };

    const changeType = (value: string) => {
        const type = types.find((option) => option.value === value);

        form.setData((data) => ({
            ...data,
            type: value as AccountTypeValue,
            on_budget: type?.onBudgetByDefault ?? data.on_budget,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const balance = parseMoney(
            form.data.balance === '' ? '0' : form.data.balance,
        );

        if (balance === null || !currentTeam) {
            form.setError('balance', t('Invalid amount, for example 1,234.56'));

            return;
        }

        form.transform((data) => ({
            ...data,
            balance,
            ...(stay ? { stay: true } : {}),
        }));
        form.post(store(currentTeam.slug).url, {
            preserveState: stay,
            preserveScroll: stay,
            onSuccess: () => changeOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-6">
                    <DialogHeader>
                        <DialogTitle>{t('Add account')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Enter the current balance of the account: it becomes its starting balance.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="account-name">{t('Name')}</Label>
                        <Input
                            id="account-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder={t('Barclays')}
                            autoFocus
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="account-type">{t('Type')}</Label>
                        <Select
                            value={form.data.type}
                            onValueChange={changeType}
                        >
                            <SelectTrigger id="account-type" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {types.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.type} />
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="account-on-budget"
                            checked={form.data.on_budget}
                            onCheckedChange={(checked) =>
                                form.setData('on_budget', checked === true)
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="account-on-budget">
                                {t('On budget account')}
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Its money is to be assigned to the envelopes. Uncheck for a loan or an investment you only track.',
                                )}
                            </p>
                        </div>
                    </div>
                    <InputError message={form.errors.on_budget} />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="account-balance">
                                {t('Current balance')}
                            </Label>
                            <Input
                                id="account-balance"
                                value={form.data.balance}
                                onChange={(event) =>
                                    form.setData('balance', event.target.value)
                                }
                                inputMode="decimal"
                                placeholder={moneyToInput(0)}
                                className="text-right tabular-nums"
                            />
                            <InputError message={form.errors.balance} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="account-date">{t('Date')}</Label>
                            <Input
                                id="account-date"
                                type="date"
                                value={form.data.starting_date}
                                onChange={(event) =>
                                    form.setData(
                                        'starting_date',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <InputError message={form.errors.starting_date} />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {t('Add the account')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
