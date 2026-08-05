<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class KYCFlowAttemptStore
{
    /**
     * @param  array<string, mixed>  $initial
     * @return array<string, mixed>
     */
    public function begin(string $flowId, string $transactionId, array $initial = []): array
    {
        return $this->withLock($flowId, function () use ($flowId, $transactionId, $initial): array {
            $state = $this->state($flowId) ?? $this->emptyState($flowId);

            foreach ($state['attempts'] as &$attempt) {
                if (($attempt['active'] ?? false) !== true || ($attempt['transaction_id'] ?? null) === $transactionId) {
                    continue;
                }

                $attempt['active'] = false;
                $attempt['superseded_at'] = now()->toIso8601String();
            }
            unset($attempt);

            $existingIndex = $this->attemptIndex($state, $transactionId);
            $defaults = [
                'transaction_id' => $transactionId,
                'sequence' => count($state['attempts']) + 1,
                'status' => 'pending',
                'application_status' => 'pending',
                'rejection_reasons' => [],
                'active' => true,
                'initiated_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => null,
                'superseded_at' => null,
            ];

            $attempt = array_replace(
                $defaults,
                $existingIndex === null ? [] : $state['attempts'][$existingIndex],
                $initial,
                [
                    'transaction_id' => $transactionId,
                    'active' => true,
                    'updated_at' => now()->toIso8601String(),
                ]);

            if ($existingIndex === null) {
                $state['attempts'][] = $attempt;
            } else {
                $state['attempts'][$existingIndex] = array_replace(
                    $state['attempts'][$existingIndex],
                    $attempt,
                );
            }

            $state['active_transaction_id'] = $transactionId;
            $state['updated_at'] = now()->toIso8601String();
            $this->put($flowId, $state);

            return $state;
        });
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function record(string $flowId, string $transactionId, array $result): array
    {
        return $this->withLock($flowId, function () use ($flowId, $transactionId, $result): array {
            $state = $this->state($flowId) ?? $this->emptyState($flowId);
            $index = $this->attemptIndex($state, $transactionId);

            if ($index === null) {
                $state['attempts'][] = [
                    'transaction_id' => $transactionId,
                    'sequence' => count($state['attempts']) + 1,
                    'status' => 'pending',
                    'application_status' => 'pending',
                    'rejection_reasons' => [],
                    'active' => $state['active_transaction_id'] === null,
                    'initiated_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                    'completed_at' => null,
                    'superseded_at' => null,
                ];
                $index = array_key_last($state['attempts']);
                $state['active_transaction_id'] ??= $transactionId;
            }

            $state['attempts'][$index] = array_replace($state['attempts'][$index], [
                'status' => (string) ($result['status'] ?? $state['attempts'][$index]['status'] ?? 'processing'),
                'application_status' => (string) ($result['application_status'] ?? $state['attempts'][$index]['application_status'] ?? 'processing'),
                'rejection_reasons' => array_values(array_filter(
                    (array) ($result['rejection_reasons'] ?? []),
                    static fn (mixed $reason): bool => is_string($reason) && trim($reason) !== '',
                )),
                'updated_at' => now()->toIso8601String(),
            ]);

            if (($state['attempts'][$index]['status'] ?? null) === 'approved') {
                $state['attempts'][$index]['completed_at'] ??= now()->toIso8601String();
            }

            $state['updated_at'] = now()->toIso8601String();
            $this->put($flowId, $state);

            return $state;
        });
    }

    /** @return array<string, mixed>|null */
    public function state(string $flowId): ?array
    {
        $state = Cache::get($this->key($flowId));

        return is_array($state) ? $state : null;
    }

    /** @return array<string, mixed>|null */
    public function active(string $flowId): ?array
    {
        $state = $this->state($flowId);

        if ($state === null) {
            return null;
        }

        $activeTransactionId = $state['active_transaction_id'] ?? null;

        foreach ($state['attempts'] ?? [] as $attempt) {
            if (($attempt['transaction_id'] ?? null) === $activeTransactionId) {
                return $attempt;
            }
        }

        return null;
    }

    public function isActive(string $flowId, string $transactionId): bool
    {
        return ($this->state($flowId)['active_transaction_id'] ?? null) === $transactionId;
    }

    public function canRetry(?array $attempt): bool
    {
        if ($attempt === null) {
            return false;
        }

        return in_array(
            (string) ($attempt['status'] ?? ''),
            (array) config('kyc-handler.retryable_statuses', [
                'needs_review',
                'rejected',
                'cancelled',
                'expired',
                'error',
            ]),
            true,
        );
    }

    public function attemptCount(string $flowId): int
    {
        return count($this->state($flowId)['attempts'] ?? []);
    }

    /** @return array<string, mixed> */
    private function emptyState(string $flowId): array
    {
        return [
            'flow_id' => $flowId,
            'active_transaction_id' => null,
            'attempts' => [],
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function attemptIndex(array $state, string $transactionId): ?int
    {
        foreach ($state['attempts'] ?? [] as $index => $attempt) {
            if (($attempt['transaction_id'] ?? null) === $transactionId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    private function withLock(string $flowId, callable $callback): mixed
    {
        return Cache::lock($this->key($flowId).'.lock', 10)->block(5, $callback);
    }

    /** @param array<string, mixed> $state */
    private function put(string $flowId, array $state): void
    {
        Cache::put(
            $this->key($flowId),
            $state,
            now()->addMinutes((int) config('kyc-handler.attempt_retention_minutes', 10_080)),
        );
    }

    private function key(string $flowId): string
    {
        return "kyc_flow_attempts.{$flowId}";
    }
}
