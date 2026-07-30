<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * The signed-in customer's landing screen inside a workspace.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        return view('core::dashboard');
    }
}
