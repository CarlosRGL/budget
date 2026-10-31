import { ArrowLeftRight, Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TransactionPickers } from '@/types';

export type PayeeValue = {
    payeeId: number | null;
    payeeName: string;
    transferAccountId: number | null;
};

type Props = {
    value: PayeeValue;
    onChange: (value: PayeeValue, lastCategoryId: number | null) => void;
    pickers: TransactionPickers;
    /** The account the transaction is on: it can't be a transfer to itself. */
    accountId: number | null;
    autoFocus?: boolean;
};

export function payeeLabel(value: PayeeValue, pickers: TransactionPickers) {
    if (value.transferAccountId !== null) {
        const account = pickers.accounts.find(
            (candidate) => candidate.id === value.transferAccountId,
        );

        return t('Transfer: :account', { account: account?.name ?? '?' });
    }

    return value.payeeName;
}

/**
 * Pick a payee, create one by typing its name, or turn the transaction into a
 * transfer by picking another account.
 */
export function PayeePicker({
    value,
    onChange,
    pickers,
    accountId,
    autoFocus,
}: Props) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const label = payeeLabel(value, pickers);
    const typed = search.trim();
    const exists = pickers.payees.some(
        (payee) => payee.name.toLowerCase() === typed.toLowerCase(),
    );

    const choose = (next: PayeeValue, lastCategoryId: number | null = null) => {
        onChange(next, lastCategoryId);
        setSearch('');
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={t('Payee')}
                    autoFocus={autoFocus}
                    className="w-full justify-between px-2 font-normal"
                >
                    <span
                        className={cn(
                            'truncate',
                            !label && 'text-muted-foreground',
                        )}
                    >
                        {label || t('Payee')}
                    </span>
                    <ChevronsUpDown className="opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <Command>
                    <CommandInput
                        placeholder={t('Search or create…')}
                        value={search}
                        onValueChange={setSearch}
                    />
                    <CommandList>
                        <CommandEmpty>{t('No payees.')}</CommandEmpty>
                        <CommandGroup heading={t('Transfer to or from')}>
                            {pickers.accounts
                                .filter(
                                    (account) =>
                                        account.id !== accountId &&
                                        !account.closed,
                                )
                                .map((account) => (
                                    <CommandItem
                                        key={account.id}
                                        value={`transfer-${account.id}`}
                                        keywords={[t('transfer'), account.name]}
                                        onSelect={() =>
                                            choose({
                                                payeeId: null,
                                                payeeName: '',
                                                transferAccountId: account.id,
                                            })
                                        }
                                    >
                                        <ArrowLeftRight />
                                        {account.name}
                                        {value.transferAccountId ===
                                            account.id && (
                                            <Check className="ml-auto" />
                                        )}
                                    </CommandItem>
                                ))}
                        </CommandGroup>
                        <CommandGroup heading={t('Payees')}>
                            {pickers.payees.map((payee) => (
                                <CommandItem
                                    key={payee.id}
                                    value={`payee-${payee.id}`}
                                    keywords={[payee.name]}
                                    onSelect={() =>
                                        choose(
                                            {
                                                payeeId: payee.id,
                                                payeeName: payee.name,
                                                transferAccountId: null,
                                            },
                                            payee.lastCategoryId,
                                        )
                                    }
                                >
                                    {payee.name}
                                    {value.payeeId === payee.id && (
                                        <Check className="ml-auto" />
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                        {typed !== '' && !exists && (
                            <CommandGroup forceMount>
                                <CommandItem
                                    value="__create__"
                                    forceMount
                                    onSelect={() =>
                                        choose({
                                            payeeId: null,
                                            payeeName: typed,
                                            transferAccountId: null,
                                        })
                                    }
                                >
                                    <Plus />
                                    {t('Create “:name”', { name: typed })}
                                </CommandItem>
                            </CommandGroup>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
