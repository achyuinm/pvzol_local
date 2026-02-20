<?php
declare(strict_types=1);

/**
 * Minimal guard/transition hooks for AMF lifecycle.
 */
final class StateMachine
{
    public function guard(string $apiName, array $state): void
    {
        // Keep guard permissive for now; reserve here for phase gating rules later.
        // Throw RuntimeException to block a method when needed.
        $_ = $apiName;
        $_2 = $state;
    }

    /**
     * @param mixed $request
     * @param mixed $response
     */
    public function transition(string $apiName, array $state, $request, $response): array
    {
        // Ensure a stable shape for state_json.
        if (!isset($state['meta']) || !is_array($state['meta'])) {
            $state['meta'] = [];
        }
        $state['meta']['last_api'] = $apiName;
        $state['meta']['last_at'] = date('Y-m-d H:i:s');

        // api.active.sign: idempotent by user + date.
        if ($apiName === 'api.active.sign') {
            $today = date('Y-m-d');
            if (!isset($state['sign']) || !is_array($state['sign'])) {
                $state['sign'] = [];
            }
            $lastDate = isset($state['sign']['last_date']) && is_string($state['sign']['last_date'])
                ? $state['sign']['last_date']
                : '';
            $isAlreadySignedToday = ($lastDate === $today);

            $state['sign']['last_request'] = $request;
            $state['sign']['last_response_type'] = gettype($response);

            if ($isAlreadySignedToday) {
                $state['sign']['idempotent'] = true;
                $state['sign']['idempotent_hits'] = (int)($state['sign']['idempotent_hits'] ?? 0) + 1;
            } else {
                $state['sign']['idempotent'] = false;
                $state['sign']['last_date'] = $today;
                $state['sign']['signed_at'] = date('Y-m-d H:i:s');
                $state['sign']['total_signed_days'] = (int)($state['sign']['total_signed_days'] ?? 0) + 1;
            }
        }

        return $state;
    }

    public function resolvePhase(string $apiName, array $state): string
    {
        if (isset($state['phase']) && is_string($state['phase']) && $state['phase'] !== '') {
            return $state['phase'];
        }
        // Keep a simple inferred phase for diagnostics.
        if (str_starts_with($apiName, 'api.fuben.') || str_starts_with($apiName, 'api.stone.')) {
            return 'world';
        }
        return 'active';
    }
}
