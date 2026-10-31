import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Pencil, Play, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { index as bankConnections } from '@/routes/bank-connections';
import { apply, destroy, index, update } from '@/routes/rename-rules';
import type { RenameRule } from '@/types';

function EditRuleDialog({ rule }: { rule: RenameRule }) {
    const { currentTeam } = usePage().props;
    const [open, setOpen] = useState(false);
    const form = useForm({
        contains: rule.contains,
        payee_name: rule.payee.name,
    });

    if (!currentTeam) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(
            update({
                current_team: currentTeam.slug,
                payee_rename_rule: rule.id,
            }).url,
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={t('Edit rule')}>
                    <Pencil />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{t('Edit rule')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'The next imports whose label contains this text will get this payee.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="contains">
                            {t('If the label contains')}
                        </Label>
                        <Input
                            id="contains"
                            value={form.data.contains}
                            onChange={(event) =>
                                form.setData('contains', event.target.value)
                            }
                        />
                        <InputError message={form.errors.contains} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="payee_name">{t('Payee')}</Label>
                        <Input
                            id="payee_name"
                            value={form.data.payee_name}
                            onChange={(event) =>
                                form.setData('payee_name', event.target.value)
                            }
                        />
                        <InputError message={form.errors.payee_name} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
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

export default function RenameRules({ rules }: { rules: RenameRule[] }) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const params = (rule: RenameRule) => ({
        current_team: currentTeam.slug,
        payee_rename_rule: rule.id,
    });

    return (
        <>
            <Head title={t('Rename rules')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    title={t('Rename rules')}
                    description={t(
                        'Bank labels that contain a text get the chosen payee. To create a rule, rename the payee of an imported transaction.',
                    )}
                />

                {rules.length === 0 ? (
                    <p className="text-muted-foreground">
                        {t('No rules yet.')}
                    </p>
                ) : (
                    <ul className="divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 max-sm:-mx-4 max-sm:rounded-none max-sm:border-x-0 dark:divide-sidebar-border dark:border-sidebar-border">
                        {rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm"
                            >
                                <span className="text-muted-foreground">
                                    {t('Contains')}
                                </span>
                                <code className="rounded bg-muted px-1.5 py-0.5">
                                    {rule.contains}
                                </code>
                                <ArrowRight className="size-4 text-muted-foreground" />
                                <span className="font-medium">
                                    {rule.payee.name}
                                </span>
                                <div className="ml-auto flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title={t(
                                            'Give this payee to the imported transactions already there',
                                        )}
                                        onClick={() =>
                                            router.post(
                                                apply(params(rule)).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Play />
                                        {t('Apply to transactions')}
                                    </Button>
                                    <EditRuleDialog rule={rule} />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('Delete rule')}
                                        className="text-red-600 dark:text-red-400"
                                        onClick={() =>
                                            router.delete(
                                                destroy(params(rule)).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

RenameRules.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: t('Bank connections'),
            href: props.currentTeam
                ? bankConnections(props.currentTeam.slug)
                : '/',
        },
        {
            title: t('Rename rules'),
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
