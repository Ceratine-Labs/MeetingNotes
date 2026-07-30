<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\ThemeService;

/**
 * Persists a signed-in user's light/dark choice.
 *
 * This endpoint is a convenience, not the mechanism. The toggle in
 * public/js/app.js has already flipped the attribute and written the cookie by
 * the time this is called, so the UI is correct whether or not the request
 * succeeds. All this adds is durability across browsers and devices by saving
 * the choice against the user row.
 *
 * That is why it is fire-and-forget on the client and why a failure here is
 * never surfaced as an error dialog.
 */
class ThemeController extends Controller
{
    public function __construct(private readonly ThemeService $themes) {}

    /**
     * Save the requested theme for the current user (and re-queue the cookie).
     *
     * Returns JSON because the only caller is a fetch() from the theme toggle.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', ThemeService::AVAILABLE)],
        ]);

        $this->themes->persist($request, $validated['theme']);

        return response()->json(['theme' => $validated['theme']]);
    }
}
