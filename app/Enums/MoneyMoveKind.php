<?php

namespace App\Enums;

enum MoneyMoveKind: string
{
    case Assign = 'assign';
    case Move = 'move';
    case AutoAssign = 'auto_assign';
    case Reset = 'reset';
    case Undo = 'undo';
    case Redo = 'redo';
}
