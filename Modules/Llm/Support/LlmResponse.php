<?php

namespace Modules\Llm\Support;

/**
 * Normalized response envelope every driver returns, so the manager
 * and pipeline never see provider-specific shapes.
 */
class LlmResponse
{
    public function __construct(
        public readonly string|array $content,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {
    }
}
