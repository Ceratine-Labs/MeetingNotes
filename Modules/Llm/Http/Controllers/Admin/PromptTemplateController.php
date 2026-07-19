<?php

namespace Modules\Llm\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Llm\Models\PromptTemplate;

class PromptTemplateController extends Controller
{
    public function index()
    {
        $templates = PromptTemplate::query()
            ->orderBy('name')
            ->orderByDesc('version')
            ->get()
            ->groupBy('name');

        return view('llm::admin.prompts.index', compact('templates'));
    }

    public function edit(PromptTemplate $promptTemplate)
    {
        return view('llm::admin.prompts.edit', ['template' => $promptTemplate]);
    }

    public function storeVersion(Request $request, PromptTemplate $promptTemplate)
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
