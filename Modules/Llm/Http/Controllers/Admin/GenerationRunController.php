<?php

namespace Modules\Llm\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Llm\Models\GenerationRun;

class GenerationRunController extends Controller
{
    /**
     * Every LLM call the application has made, with cost and token totals.
     */
    public function index(): View
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
