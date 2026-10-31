export type BudgetTarget = {
    type: 'refill_up_to' | 'set_aside' | 'weekly' | 'by_date';
    amount: number;
    weekday: number | null;
    dueDate: string | null;
    repeat: 'yearly' | null;
    needed: number;
    funded: number;
    toGo: number;
    ratio: number;
    snoozed: boolean;
    underfunded: boolean;
    overfunded: boolean;
};

export type BudgetCategory = {
    id: number;
    name: string;
    hidden: boolean;
    note: string | null;
    carryover: number;
    assigned: number;
    activity: number;
    available: number;
    target: BudgetTarget | null;
};

export type BudgetGroup = {
    id: number;
    name: string;
    hidden: boolean;
    assigned: number;
    activity: number;
    available: number;
    categories: BudgetCategory[];
};

export type MoneyMoveEnd = { id: number; name: string } | null;

export type BudgetMoneyMove = {
    id: number;
    kind: 'assign' | 'move' | 'auto_assign' | 'reset';
    amount: number;
    /** null = Ready to Assign */
    from: MoneyMoveEnd;
    to: MoneyMoveEnd;
    user: string | null;
    at: string;
};

export type BudgetCategoryDetail = {
    categoryId: number;
    carryover: number;
    assigned: number;
    activity: number;
    available: number;
    assignedLastMonth: number;
    spentLastMonth: number;
    averageAssigned: number;
    averageSpent: number;
};

export type BudgetPageProps = {
    month: string;
    previousMonth: string;
    nextMonth: string;
    readyToAssign: number;
    uncategorizedActivity: number;
    overspentCount: number;
    underfundedCount: number;
    note: string | null;
    groups: BudgetGroup[];
    moves: BudgetMoneyMove[];
    detail?: BudgetCategoryDetail | null;
};
