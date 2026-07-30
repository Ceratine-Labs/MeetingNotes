<?php

/*
|--------------------------------------------------------------------------
| Search configuration
|--------------------------------------------------------------------------
|
| Merged under the `search` key by SearchServiceProvider.
|
*/

return [

    /*
    | Milliseconds the navbar search box waits after the last keystroke before it asks
    | the server anything. The timer resets on every keystroke, so a continuous typist
    | triggers exactly one request when they stop.
    |
    | 1500 ms is Ryan's call. It is deliberately long: each request runs a full-text
    | query across the workspace, and a shorter delay means firing several of them for
    | a single word nobody has finished typing yet.
    */
    'debounce_ms' => (int) env('SEARCH_DEBOUNCE_MS', 1500),

];
