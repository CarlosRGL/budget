import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Combine, Pencil, Search, Trash2, Wand2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { categoryLabel } from '@/components/transactions/category-picker';
import { PayeeSettingsFields } from '@/components/transactions/payee-settings-fields';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { destroy, index, merge, update } from '@/routes/payees';
import { index as renameRules } from '@/routes/rename-rules';
import type { CategoryMode, TransactionPickers } from '@/types';
import { intlLocale, t, tc } from '@/lib/i18n';

type Payee = {
    id: number;
    name: string;
    categoryId: number | null;
    categoryMode: CategoryMode;
    transactions: number;
    rules: string[];
    lastUsed: string | null;
};

type Props = {
    payees: Payee[];
    pickers: TransactionPickers;
};

const dateFormat = new Intl.DateTimeFormat(intlLocale(), {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

function defaultCategoryLabel(
    payee: Payee,
    pickers: TransactionPickers,
): string {
    const category = categoryLabel(
        { categoryId: payee.categoryId, inflowToRta: false },
        pickers,
    );

    return payee.categoryMode === 'fixed'
        ? t('Always :category', { category })
        : category;
}

function EditPayeeDialog({
    payee,
    pickers,
    onClose,
}: {
    payee: Payee;
    pickers: TransactionPickers;
    onClose: () => void;
}) {
    const { currentTeam } = usePage().props;
    const form = useForm({
        name: payee.name,
        categoryMode: payee.categoryMode,
        categoryId: payee.categoryId,
        rules: payee.rules,
        applyToExisting: true,
    });
    const taken = pickers.payees.some(
        (other) =>
            other.id !== payee.id &&
            other.name.toLocaleLowerCase(intlLocale()) ===
                form.data.name.trim().toLocaleLowerCase(intlLocale()),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            category_id: data.categoryId,
            category_mode: data.categoryMode,
            rules: data.rules.map((rule) => rule.trim()).filter(Boolean),
            apply_to_existing: data.applyToExisting,
        }));
        form.patch(
            update({ current_team: currentTeam!.slug, payee: payee.id }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{t('Edit payee')}</DialogTitle>
                        <DialogDescription>
                            {t('The new name applies to all its transactions.')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="payee-name">{t('Name')}</Label>
                        <Input
                            id="payee-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {taken && (
                            <p className="text-xs text-amber-700 dark:text-amber-400">
                                {t(
                                    'This name already exists: the two payees will be merged.',
                                )}
                            </p>
                        )}
                        <InputError message={form.errors.name} />
                    </div>

                    <PayeeSettingsFields
                        value={form.data}
                        onChange={(changes) =>
                            form.setData((data) => ({ ...data, ...changes }))
                        }
                        pickers={pickers}
                        errors={form.errors as Record<string, string>}
                        payee={{ id: payee.id, name: form.data.name }}
                    />

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {taken ? t('Merge') : t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MergeDialog({
    payees,
    onClose,
    onMerged,
}: {
    payees: Payee[];
    onClose: () => void;
    onMerged: () => void;
}) {
    const { currentTeam } = usePage().props;
    const [into, setInto] = useState(
        // The most used one is usually the right name.
        [...payees].sort((a, b) => b.transactions - a.transactions)[0].id,
    );
    const [processing, setProcessing] = useState(false);

    const submit = () =>
        router.post(
            merge(currentTeam!.slug).url,
            { into, ids: payees.map(({ id }) => id) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onMerged,
            },
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {tc(
                            'Merge :count payee|Merge :count payees',
                            payees.length,
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'Their transactions and rename rules move to the kept name; the others disappear.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <fieldset className="space-y-1">
                    <legend className="mb-2 text-sm font-medium">
                        {t('Name to keep')}
                    </legend>
                    {payees.map((payee) => (
                        <label
                            key={payee.id}
                            className={cn(
                                'flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm',
                                into === payee.id &&
                                    'border-foreground/40 bg-muted/50',
                            )}
                        >
                            <input
                                type="radio"
                                name="into"
                                checked={into === payee.id}
                                onChange={() => setInto(payee.id)}
                                className="accent-foreground"
                            />
                            <span className="flex-1 truncate">
                                {payee.name}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {t(':count txn.', {
                                    count: payee.transactions,
                                })}
                            </span>
                        </label>
                    ))}
                </fieldset>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {t('Cancel')}
                        </Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={processing}>
                        <Combine />
                        {t('Merge')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The household's payees: rename, default category, merge, delete.
 */
export default function PayeesIndex({ payees, pickers }: Props) {
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [editing, setEditing] = useState<Payee | null>(null);
    const [merging, setMerging] = useState(false);

    if (!currentTeam) {
        return null;
    }

    const query = search.trim().toLocaleLowerCase(intlLocale());
    const visible = payees.filter(
        (payee) =>
            query === '' ||
            payee.name.toLocaleLowerCase(intlLocale()).includes(query),
    );
    const selectedPayees = payees.filter((payee) =>
        selected.includes(payee.id),
    );

    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((candidate) => candidate !== id)
                : [...current, id],
        );

    const remove = (payee: Payee) => {
        const message =
            payee.transactions > 0
                ? tc(
                      'Delete “:name”? Its :count transaction will remain, without a payee.|Delete “:name”? Its :count transactions will remain, without a payee.',
                      payee.transactions,
                      { name: payee.name },
                  )
                : t('Delete “:name”?', { name: payee.name });

        if (window.confirm(message)) {
            router.delete(
                destroy({ current_team: currentTeam.slug, payee: payee.id })
                    .url,
                {
                    preserveScroll: true,
                    onSuccess: () =>
                        setSelected((current) =>
                            current.filter((id) => id !== payee.id),
                        ),
                },
            );
        }
    };

    return (
        <>
            <Head title={t('Payees')} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title={t('Payees')}
                        description={t(
                            'Rename them, give them a default category, merge duplicates.',
                        )}
                    />
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={renameRules(currentTeam.slug)}>
                            <Wand2 />
                            {t('Rename rules')}
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <label className="relative flex-1 sm:max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            aria-label={t('Search for a payee')}
                            placeholder={t('Search…')}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="h-8 w-full rounded-md border bg-transparent pr-2 pl-8 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                        />
                    </label>
                    <span className="text-sm text-muted-foreground">
                        {tc(':count payee|:count payees', payees.length)}
                    </span>
                    {selected.length > 0 && (
                        <div className="ml-auto flex gap-2">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setSelected([])}
                            >
                                {t('Deselect')}
                            </Button>
                            <Button
                                size="sm"
                                disabled={selected.length < 2}
                                onClick={() => setMerging(true)}
                            >
                                <Combine />
                                {t('Merge (:count)', {
                                    count: selected.length,
                                })}
                            </Button>
                        </div>
                    )}
                </div>

                <ul className="divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:divide-sidebar-border dark:border-sidebar-border">
                    {visible.map((payee) => (
                        <li
                            key={payee.id}
                            className={cn(
                                'group flex items-center gap-3 px-3 py-2 text-sm hover:bg-muted/40',
                                selected.includes(payee.id) && 'bg-muted/50',
                            )}
                        >
                            <Checkbox
                                aria-label={t('Select :name', {
                                    name: payee.name,
                                })}
                                checked={selected.includes(payee.id)}
                                onCheckedChange={() => toggle(payee.id)}
                            />
                            <button
                                type="button"
                                className="min-w-0 flex-1 text-left"
                                onClick={() => setEditing(payee)}
                            >
                                <span className="block truncate font-medium">
                                    {payee.name}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {payee.categoryMode === 'none'
                                        ? t('No categorization')
                                        : payee.categoryId !== null
                                          ? defaultCategoryLabel(payee, pickers)
                                          : t('No default category')}
                                    {payee.rules.length > 0 &&
                                        ` · ${t('contains :texts', {
                                            texts: payee.rules
                                                .map((rule) =>
                                                    t('“:name”', {
                                                        name: rule,
                                                    }),
                                                )
                                                .join(', '),
                                        })}`}
                                </span>
                            </button>
                            <div className="hidden text-right text-xs text-muted-foreground tabular-nums sm:block">
                                <div>
                                    {tc(
                                        ':count transaction|:count transactions',
                                        payee.transactions,
                                    )}
                                </div>
                                {payee.lastUsed && (
                                    <div>
                                        {dateFormat.format(
                                            new Date(
                                                `${payee.lastUsed.slice(0, 10)}T12:00:00`,
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>
                            <div className="flex shrink-0 gap-0.5">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={t('Edit :name', {
                                        name: payee.name,
                                    })}
                                    onClick={() => setEditing(payee)}
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 text-muted-foreground hover:text-red-600 dark:hover:text-red-400"
                                    aria-label={t('Delete :name', {
                                        name: payee.name,
                                    })}
                                    onClick={() => remove(payee)}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </li>
                    ))}
                    {visible.length === 0 && (
                        <li className="px-4 py-10 text-center text-sm text-muted-foreground">
                            {payees.length === 0
                                ? t(
                                      'No payees yet: they are created when you type a transaction.',
                                  )
                                : t('No payees match.')}
                        </li>
                    )}
                </ul>
            </div>

            {editing && (
                <EditPayeeDialog
                    key={editing.id}
                    payee={editing}
                    pickers={pickers}
                    onClose={() => setEditing(null)}
                />
            )}
            {merging && selectedPayees.length > 1 && (
                <MergeDialog
                    payees={selectedPayees}
                    onClose={() => setMerging(false)}
                    onMerged={() => {
                        setMerging(false);
                        setSelected([]);
                    }}
                />
            )}
        </>
    );
}

PayeesIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('Payees'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
