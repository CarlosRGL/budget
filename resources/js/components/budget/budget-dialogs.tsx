import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import type { BudgetCategory, BudgetGroup } from '@/types/budget';

type NameDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    label: string;
    initialName?: string;
    submitLabel: string;
    onSubmit: (name: string, close: () => void) => void;
};

/**
 * Ask for a name: new group, new category, or renaming one.
 */
export function NameDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    initialName = '',
    submitLabel,
    onSubmit,
}: NameDialogProps) {
    const [name, setName] = useState(initialName);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (name.trim() !== '') {
            onSubmit(name.trim(), () => onOpenChange(false));
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setName(initialName);
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="budget-name">{label}</Label>
                        <Input
                            id="budget-name"
                            autoFocus
                            value={name}
                            maxLength={255}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={name.trim() === ''}>
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type DeleteCategoryDialogProps = {
    category: BudgetCategory | null;
    groups: BudgetGroup[];
    onOpenChange: (open: boolean) => void;
    onConfirm: (replacementId: number, close: () => void) => void;
};

/**
 * Deleting a category moves its transactions, assignments and money moves to
 * another category, chosen here.
 */
export function DeleteCategoryDialog({
    category,
    groups,
    onOpenChange,
    onConfirm,
}: DeleteCategoryDialogProps) {
    const [replacement, setReplacement] = useState('');

    return (
        <Dialog
            open={category !== null}
            onOpenChange={(next) => {
                setReplacement('');
                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('Delete “:name”', { name: category?.name ?? '' })}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'Its transactions, assignments and history move to the chosen category. Nothing is lost.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2">
                    <Label>{t('Transfer to')}</Label>
                    <Select value={replacement} onValueChange={setReplacement}>
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder={t('Choose a category')} />
                        </SelectTrigger>
                        <SelectContent>
                            {groups.map((group) => (
                                <SelectGroup key={group.id}>
                                    <SelectLabel>{group.name}</SelectLabel>
                                    {group.categories
                                        .filter(
                                            (other) =>
                                                other.id !== category?.id,
                                        )
                                        .map((other) => (
                                            <SelectItem
                                                key={other.id}
                                                value={String(other.id)}
                                            >
                                                {other.name}
                                            </SelectItem>
                                        ))}
                                </SelectGroup>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={replacement === ''}
                        onClick={() =>
                            onConfirm(Number(replacement), () =>
                                onOpenChange(false),
                            )
                        }
                    >
                        {t('Delete')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
