<?php

namespace Modules\Llm\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Llm\Models\PromptTemplate;

class PromptTemplateController extends Controller
{
    /**
     * All prompt templates and their active versions.
     */
    public function index(): View
    {
        $templates = PromptTemplate::query()
            ->orderBy('name')
            ->orderByDesc('version')
            ->get()
            ->groupBy('name');

        return view('llm::admin.prompts.index', compact('templates'));
    }

    /**
     * Edit one template.
     */
    public function edit(PromptTemplate $promptTemplate): View
    {
        return view('llm::admin.prompts.edit', ['template' => $promptTemplate]);
    }

    /**
     * Save a new version of a template.
     *
     * Versions are appended, never edited in place, so a prompt change that makes
     * output worse can be traced and reverted.
     */
    public function storeVersion(Request $request, PromptTemplate $promptTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:100000'],
        ]);

        $new = $promptTemplate->publishNewVersion($validated['body']);

        return redirect()
            ->route('llm.admin.prompts')
            ->with('status', "Published {$new->name} v{$new->version} (now active).");
    }
}
