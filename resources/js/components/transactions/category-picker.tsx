import { Check, ChevronsUpDown, Split } from 'lucide-react';
import { useState } from 'react';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TransactionPickers } from '@/types';

export type CategoryValue = {
    categoryId: number | null;
    inflowToRta: boolean;
};

type Props = {
    value: CategoryValue;
    onChange: (value: CategoryValue) => void;
    pickers: TransactionPickers;
    /** Offer to split the transaction over several categories. */
    onSplit?: () => void;
    disabled?: boolean;
    placeholder?: string;
};

export function categoryLabel(
    value: CategoryValue,
    pickers: TransactionPickers,
): string {
    if (value.inflowToRta) {
        return t('Ready to Assign');
    }

    for (const group of pickers.categoryGroups) {
        const category = group.categories.find(
            (candidate) => candidate.id === value.categoryId,
        );

        if (category) {
            return category.name;
        }
    }

    return '';
}

/**
 * Pick the envelope of a transaction, seeing what is left in each one this month.
 */
export function CategoryPicker({
    value,
    onChange,
    pickers,
    onSplit,
    disabled,
    placeholder = t('Category'),
}: Props) {
    const [open, setOpen] = useState(false);
    const label = categoryLabel(value, pickers);

    const choose = (next: CategoryValue) => {
        onChange(next);
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
                    aria-label={t('Category')}
                    disabled={disabled}
                    className="w-full justify-between px-2 font-normal"
                >
                    <span
                        className={cn(
                            'truncate',
                            !label && 'text-muted-foreground',
                        )}
                    >
                        {label || placeholder}
                    </span>
                    <ChevronsUpDown className="opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="start">
                <Command>
                    <CommandInput placeholder={t('Search for a category…')} />
                    <CommandList className="max-h-80">
                        <CommandEmpty>{t('No categories.')}</CommandEmpty>
                        <CommandGroup heading={t('Inflow')}>
                            <CommandItem
                                value="ready-to-assign"
                                keywords={[t('Ready to Assign'), t('income')]}
                                onSelect={() =>
                                    choose({
                                        categoryId: null,
                                        inflowToRta: true,
                                    })
                                }
                            >
                                {t('Ready to Assign')}
                                <Money
                                    cents={pickers.readyToAssign}
                                    positive
                                    className="ml-auto text-xs"
                                />
                            </CommandItem>
                        </CommandGroup>
                        {pickers.categoryGroups
                            .filter((group) => !group.hidden)
                            .map((group) => (
                                <CommandGroup
                                    key={group.id}
                                    heading={group.name}
                                >
                                    {group.categories
                                        .filter(
                                            (category) =>
                                                !category.hidden ||
                                                category.id ===
                                                    value.categoryId,
                                        )
                                        .map((category) => (
                                            <CommandItem
                                                key={category.id}
                                                value={`category-${category.id}`}
                                                keywords={[
                                                    category.name,
                                                    group.name,
                                                ]}
                                                onSelect={() =>
                                                    choose({
                                                        categoryId: category.id,
                                                        inflowToRta: false,
                                                    })
                                                }
                                            >
                                                {value.categoryId ===
                                                    category.id && <Check />}
                                                <span className="truncate">
                                                    {category.name}
                                                </span>
                                                <Money
                                                    cents={category.available}
                                                    positive
                                                    className="ml-auto text-xs"
                                                />
                                            </CommandItem>
                                        ))}
                                </CommandGroup>
                            ))}
                        {onSplit && (
                            <>
                                <CommandSeparator />
                                <CommandGroup>
                                    <CommandItem
                                        value="split"
                                        keywords={[t('split'), 'split']}
                                        onSelect={() => {
                                            setOpen(false);
                                            onSplit();
                                        }}
                                    >
                                        <Split />
                                        {t('Split the transaction')}
                                    </CommandItem>
                                </CommandGroup>
                            </>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
