import { Minus, Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import { CategoryPicker } from '@/components/transactions/category-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { intlLocale, t } from '@/lib/i18n';
import type { CategoryMode, TransactionPickers } from '@/types';

export type PayeeSettings = {
    categoryMode: CategoryMode;
    categoryId: number | null;
    rules: string[];
    applyToExisting: boolean;
};

export const categoryModes: {
    value: CategoryMode;
    label: string;
    hint: string;
}[] = [
    {
        value: 'history',
        label: t('From the last transaction'),
        hint: t(
            'The category follows that of its last categorized transaction.',
        ),
    },
    {
        value: 'fixed',
        label: t('Always this category'),
        hint: t('Its new transactions always get the chosen category.'),
    },
    {
        value: 'none',
        label: t('Do not categorize'),
        hint: t('Each transaction is categorized by hand.'),
    },
];

type Props = {
    value: PayeeSettings;
    onChange: (changes: Partial<PayeeSettings>) => void;
    pickers: TransactionPickers;
    /** Validation errors, keyed as sent: `category_id`, `rules.0`… */
    errors?: Record<string, string | undefined>;
    /** Prefix of the error keys, e.g. `payee_settings.`. */
    errorPrefix?: string;
    /** A bank label worth offering as a rule in one click. */
    suggestedRule?: string | null;
};

/**
 * How a payee categorizes, the bank labels it takes over, and whether to run
 * that on the transactions already imported. Shared by the payee dialog and
 * the transaction editor.
 */
export function PayeeSettingsFields({
    value,
    onChange,
    pickers,
    errors = {},
    errorPrefix = '',
    suggestedRule = null,
}: Props) {
    const setRule = (index: number, text: string) =>
        onChange({
            rules: value.rules.map((rule, i) => (i === index ? text : rule)),
        });

    const suggestion = suggestedRule?.trim() ?? '';
    const offerSuggestion =
        suggestion.length >= 2 &&
        !value.rules.some(
            (rule) =>
                rule.trim().toLocaleLowerCase(intlLocale()) ===
                suggestion.toLocaleLowerCase(intlLocale()),
        );

    return (
        <>
            <fieldset className="grid gap-2">
                <legend className="mb-1.5 text-sm font-medium">
                    {t('Categorization')}
                </legend>
                {categoryModes.map((mode) => (
                    <label
                        key={mode.value}
                        className="flex cursor-pointer gap-3 text-sm"
                    >
                        <input
                            type="radio"
                            checked={value.categoryMode === mode.value}
                            onChange={() =>
                                onChange({ categoryMode: mode.value })
                            }
                            className="mt-1 accent-foreground"
                        />
                        <span>
                            <span className="block">{mode.label}</span>
                            <span className="block text-xs text-muted-foreground">
                                {mode.hint}
                            </span>
                        </span>
                    </label>
                ))}
                {value.categoryMode !== 'none' && (
                    <div className="mt-1 grid gap-1.5 pl-6">
                        <CategoryPicker
                            value={{
                                categoryId: value.categoryId,
                                inflowToRta: false,
                            }}
                            onChange={(category) =>
                                onChange({
                                    categoryId: category.inflowToRta
                                        ? null
                                        : category.categoryId,
                                })
                            }
                            pickers={pickers}
                            placeholder={t('No category')}
                        />
                        <InputError
                            message={errors[`${errorPrefix}category_id`]}
                        />
                    </div>
                )}
            </fieldset>

            <fieldset className="grid gap-2">
                <legend className="mb-0.5 text-sm font-medium">
                    {t('Automatic renaming')}
                </legend>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Bank labels that contain this text get this payee, regardless of case. For example “ALDI” for all Aldi stores.',
                    )}
                </p>
                {value.rules.map((rule, index) => (
                    <div key={index} className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <span className="w-16 shrink-0 text-xs text-muted-foreground">
                                {t('Contains')}
                            </span>
                            <Input
                                aria-label={t('Label text')}
                                value={rule}
                                onChange={(event) =>
                                    setRule(index, event.target.value)
                                }
                                className="h-8"
                                autoFocus={rule === ''}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8 shrink-0"
                                aria-label={t('Remove the rule')}
                                onClick={() =>
                                    onChange({
                                        rules: value.rules.filter(
                                            (_, i) => i !== index,
                                        ),
                                    })
                                }
                            >
                                <Minus />
                            </Button>
                        </div>
                        <InputError
                            message={errors[`${errorPrefix}rules.${index}`]}
                        />
                    </div>
                ))}
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onChange({ rules: [...value.rules, ''] })
                        }
                    >
                        <Plus />
                        {t('Add a rule')}
                    </Button>
                    {offerSuggestion && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="max-w-full"
                            onClick={() =>
                                onChange({
                                    rules: [...value.rules, suggestion],
                                })
                            }
                        >
                            <Plus />
                            <span className="truncate">
                                {t('Contains “:text”', { text: suggestion })}
                            </span>
                        </Button>
                    )}
                </div>
            </fieldset>

            <label className="flex cursor-pointer items-start gap-3 rounded-lg bg-muted/50 p-3 text-sm">
                <Checkbox
                    className="mt-0.5"
                    checked={value.applyToExisting}
                    onCheckedChange={(checked) =>
                        onChange({ applyToExisting: checked === true })
                    }
                />
                <span>
                    <span className="block">
                        {t('Apply to transactions already imported')}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {t(
                            'Renames those that match the rules and gives the category to those that have none.',
                        )}
                    </span>
                </span>
            </label>
        </>
    );
}
