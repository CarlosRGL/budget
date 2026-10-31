<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * The kit's dashboard is the budget of the current month.
 */
class DashboardController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return to_route('budget.show');
    }
}
