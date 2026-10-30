<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Entry of the «Справочники» section: opens the first list the employee may view.
 */
class ReferenceSectionController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        if (Gate::allows('order-references.view')) {
            return to_route('order-statuses.index');
        }

        if (Gate::allows('source-references.view')) {
            return to_route('source-references.index', 'kinds');
        }

        abort(403);
    }
}
