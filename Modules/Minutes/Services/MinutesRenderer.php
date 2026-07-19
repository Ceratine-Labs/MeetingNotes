<?php

namespace Modules\Minutes\Services;

/**
 * Renders the canonical sections struct to HTML via a Blade template
 * the LLM never touches — the guarantee that every minutes record
 * looks identical in structure. The result is cached on
 * meetings.rendered_html and reused by the workspace, print view and
 * exports.
 */
class MinutesRenderer
{
    public function render(array $sections): string
    {
        return view('minutes::render.minutes', ['s' => $sections])->render();
    }
}
