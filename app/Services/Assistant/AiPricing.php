<?php

namespace App\Services\Assistant;

/**
 * Turns an Anthropic `usage` block into a USD cost estimate from the per-model
 * price map in config('ekdosi.ai.pricing'). Tokens are authoritative; cost is
 * OUR estimate (reconciled to the monthly invoice). Cache reads are ~0.1× input,
 * cache writes ~1.25× input (Anthropic's prompt-cache economics).
 */
class AiPricing
{
    /**
     * @param  array{input_tokens?:int,output_tokens?:int,cache_read_input_tokens?:int,cache_creation_input_tokens?:int}  $usage
     */
    public function estimate(string $model, array $usage): float
    {
        $rates = config('ekdosi.ai.pricing.'.$model);
        if (! is_array($rates)) {
            return 0.0; // unknown model → no estimate (tokens still logged)
        }

        $in = (float) $rates['input'];
        $out = (float) $rates['output'];

        $cost =
            ($usage['input_tokens'] ?? 0) * $in
            + ($usage['output_tokens'] ?? 0) * $out
            + ($usage['cache_read_input_tokens'] ?? 0) * $in * 0.1
            + ($usage['cache_creation_input_tokens'] ?? 0) * $in * 1.25;

        // Rates are per 1M tokens.
        return round($cost / 1_000_000, 4);
    }
}
