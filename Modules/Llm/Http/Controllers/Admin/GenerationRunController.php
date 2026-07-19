<?php

namespace Modules\Llm\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Modules\Llm\Models\GenerationRun;

class GenerationRunController extends Controller
{
    public function index()
    {
        $runs = GenerationRun::query()
            ->orderByDesc('created_at')
            ->paginate(50);

        $totals = [
            'tokens_in' => (int) GenerationRun::query()->sum('tokens_in'),
            'tokens_out' => (int) GenerationRun::query()->sum('tokens_out'),
            'cost' => (float) GenerationRun::query()->sum('cost_estimate'),
            'errors' => GenerationRun::query()->where('status', 'error')->count(),
        ];

        return view('llm::admin.runs', compact('runs', 'totals'));
    }
}
