<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('core::dashboard');
    }
}
