<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LBHurtado\FormFlowManager\Services\FormFlowService;
use LBHurtado\FormHandlerKYC\Actions\FetchKYCResult;
use LBHurtado\FormHandlerKYC\Actions\InitiateKYC;
use LBHurtado\FormHandlerKYC\Services\KYCFlowAttemptStore;
use Throwable;

final class KYCFlowController extends Controller
{
    public function __construct(
        private readonly FormFlowService $flows,
        private readonly KYCFlowAttemptStore $attempts,
    ) {}

    public function initiate(Request $request, string $flow_id): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $mobile = trim((string) $request->input('mobile'));
        $country = mb_strtoupper(trim((string) $request->input('country', 'PH')));
        $stepIndex = (int) $request->input('step_index', 0);

        Log::info('[KYCFlowController::initiate] Starting KYC initiation', [
            'flow_id' => $flow_id,
            'step_index' => $stepIndex,
            'has_mobile' => $mobile !== '',
        ]);

        if ($mobile === '') {
            return redirect()
                ->back()
                ->with('error', 'Mobile number is required for KYC verification.');
        }

        if (($this->attempts->active($flow_id)['status'] ?? null) === 'approved') {
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('success', 'Identity already verified!');
        }

        return $this->startAttempt(
            flowId: $flow_id,
            mobile: $mobile,
            country: $country,
            stepIndex: $stepIndex,
            flowState: $this->flows->getFlowState($flow_id),
        );
    }

    public function retry(string $flow_id): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $activeAttempt = $this->activeAttemptFromSharedOrLegacyState($flow_id);

        if (! $this->attempts->canRetry($activeAttempt)) {
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('error', 'This identity check is not eligible for another attempt.');
        }

        $transactionId = (string) ($activeAttempt['transaction_id'] ?? '');
        $context = Cache::get("kyc_context.{$transactionId}");

        if (! is_array($context)) {
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('error', 'The identity-check context expired. Restart the Pay Code claim.');
        }

        return $this->startAttempt(
            flowId: $flow_id,
            mobile: (string) ($context['mobile'] ?? ''),
            country: (string) ($context['country'] ?? 'PH'),
            stepIndex: (int) ($context['step_index'] ?? 0),
            flowState: $this->flows->getFlowState($flow_id) ?? ($context['flow_state'] ?? null),
        );
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse|Response
    {
        $callbackStatus = trim((string) $request->query('status'));
        $transactionId = trim((string) $request->query('transactionId'));

        Log::info('[KYCFlowController] KYC callback received', [
            'transaction_id' => $transactionId,
            'status' => $callbackStatus,
        ]);

        $context = Cache::get("kyc_context.{$transactionId}");

        if (! is_array($context)) {
            Log::error('[KYCFlowController] No context found for transaction_id', [
                'transaction_id' => $transactionId,
            ]);

            return response('Transaction context not found. Please restart KYC.', 404);
        }

        $flowId = (string) $context['flow_id'];
        $stepIndex = (int) $context['step_index'];
        $this->restoreFlowStateWhenMissing($flowId, $context['flow_state'] ?? null);

        try {
            $kycData = FetchKYCResult::run($transactionId);

            if (in_array($callbackStatus, ['user_cancelled', 'cancelled'], true) && ($kycData['status'] ?? null) !== 'approved') {
                $kycData['status'] = 'cancelled';
                $kycData['application_status'] = $callbackStatus;
            }

            $this->attempts->record($flowId, $transactionId, $kycData);
        } catch (Throwable $exception) {
            if ($this->recordUnavailableProviderTransactionWhenGraceExpired($flowId, $transactionId, $exception)) {
                return $this->statusPage(
                    flowId: $flowId,
                    transactionId: $transactionId,
                    initialStatus: 'error',
                    canRetry: true,
                );
            }

            Log::error('[KYCFlowController] Failed to fetch KYC results', [
                'flow_id' => $flowId,
                'transaction_id' => $transactionId,
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('form-flow.show', ['flow_id' => $flowId])
                ->with('error', 'Failed to fetch KYC results.');
        }

        if (! $this->attempts->isActive($flowId, $transactionId)) {
            return $this->statusPage(
                flowId: $flowId,
                transactionId: $transactionId,
                initialStatus: 'superseded',
                canRetry: false,
            );
        }

        if (($kycData['status'] ?? null) === 'approved') {
            $this->completeApprovedAttempt($flowId, $stepIndex, $transactionId, $kycData);

            return redirect()
                ->route('form-flow.show', ['flow_id' => $flowId])
                ->with('success', 'Identity verified successfully!');
        }

        $initialStatus = trim((string) ($kycData['status'] ?? ''));

        if ($initialStatus === '') {
            $initialStatus = $callbackStatus !== '' ? $callbackStatus : 'processing';
        }

        return $this->statusPage(
            flowId: $flowId,
            transactionId: $transactionId,
            initialStatus: $initialStatus,
            canRetry: $this->attempts->canRetry($this->attempts->active($flowId)),
        );
    }

    public function status(string $flow_id): JsonResponse
    {
        $activeAttempt = $this->activeAttemptFromSharedOrLegacyState($flow_id);

        if ($activeAttempt === null) {
            return response()->json([
                'status' => 'unavailable',
                'error' => 'Identity-check state is unavailable. Restart the Pay Code claim.',
                'can_retry' => false,
                'attempt_count' => 0,
            ]);
        }

        $transactionId = (string) $activeAttempt['transaction_id'];
        $context = Cache::get("kyc_context.{$transactionId}");
        $status = (string) ($activeAttempt['status'] ?? 'processing');

        if (in_array($status, ['pending', 'processing', 'needs_review'], true)) {
            try {
                $result = FetchKYCResult::run($transactionId);
                $this->attempts->record($flow_id, $transactionId, $result);
                $activeAttempt = $this->attempts->active($flow_id) ?? $activeAttempt;
                $status = (string) ($activeAttempt['status'] ?? 'processing');

                if ($status === 'approved' && is_array($context)) {
                    $this->restoreFlowStateWhenMissing($flow_id, $context['flow_state'] ?? null);
                    $this->completeApprovedAttempt(
                        $flow_id,
                        (int) ($context['step_index'] ?? 0),
                        $transactionId,
                        $result,
                    );
                }
            } catch (Throwable $exception) {
                $this->recordUnavailableProviderTransactionWhenGraceExpired(
                    $flow_id,
                    $transactionId,
                    $exception,
                );

                Log::debug('[KYCFlowController] Results not ready yet', [
                    'flow_id' => $flow_id,
                    'transaction_id' => $transactionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $activeAttempt = $this->attempts->active($flow_id) ?? $activeAttempt;

        return response()->json($this->statusPayload($flow_id, $activeAttempt));
    }

    private function recordUnavailableProviderTransactionWhenGraceExpired(
        string $flowId,
        string $transactionId,
        Throwable $exception,
    ): bool {
        if (! $this->isProviderTransactionUnavailable($exception)) {
            return false;
        }

        $attempt = $this->attempts->active($flowId);

        if (($attempt['transaction_id'] ?? null) !== $transactionId) {
            return false;
        }

        $initiatedAt = CarbonImmutable::parse((string) ($attempt['initiated_at'] ?? now()->toIso8601String()));
        $graceSeconds = max(0, (int) config('kyc-handler.provider_result_grace_seconds', 120));

        if ($initiatedAt->addSeconds($graceSeconds)->isFuture()) {
            return false;
        }

        $this->attempts->record($flowId, $transactionId, [
            'status' => 'error',
            'application_status' => 'provider_transaction_unavailable',
            'rejection_reasons' => [
                'The identity provider could not locate this attempt. Start a new identity check.',
            ],
        ]);

        Log::warning('[KYCFlowController] Provider could not locate KYC attempt after grace period', [
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'grace_seconds' => $graceSeconds,
        ]);

        return true;
    }

    private function isProviderTransactionUnavailable(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->response;

        return $response->status() === 422
            && mb_strtolower(trim((string) data_get($response->json(), 'result.error'))) === 'transactionid not found';
    }

    /**
     * @param  array<string, mixed>|null  $flowState
     */
    private function startAttempt(
        string $flowId,
        string $mobile,
        string $country,
        int $stepIndex,
        ?array $flowState,
    ): RedirectResponse|\Symfony\Component\HttpFoundation\Response {
        try {
            $kycData = InitiateKYC::run($flowId, $mobile, $country);
            Session::put("form_flow.{$flowId}.kyc", $kycData);

            if (is_array($flowState)) {
                $flowState['current_step'] = $stepIndex;
            }

            Cache::put("kyc_context.{$kycData['transaction_id']}", [
                'flow_id' => $flowId,
                'step_index' => $stepIndex,
                'mobile' => $mobile,
                'country' => $country,
                'flow_state' => $flowState,
                'initiated_at' => now()->toIso8601String(),
            ], now()->addMinutes((int) config('kyc-handler.attempt_retention_minutes', 10_080)));

            $this->attempts->begin($flowId, (string) $kycData['transaction_id'], [
                'status' => (string) ($kycData['status'] ?? 'pending'),
                'application_status' => (string) ($kycData['status'] ?? 'pending'),
            ]);

            if (config('kyc-handler.use_fake', false)) {
                return redirect($kycData['onboarding_url']);
            }

            return Inertia::location($kycData['onboarding_url']);
        } catch (Throwable $exception) {
            Log::error('[KYCFlowController] Failed to initiate KYC', [
                'flow_id' => $flowId,
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Failed to start KYC verification. Please try again.');
        }
    }

    /** @return array<string, mixed>|null */
    private function activeAttemptFromSharedOrLegacyState(string $flowId): ?array
    {
        $activeAttempt = $this->attempts->active($flowId);

        if ($activeAttempt !== null) {
            return $activeAttempt;
        }

        $legacy = Session::get("form_flow.{$flowId}.kyc");

        if (! is_array($legacy) || ! is_string($legacy['transaction_id'] ?? null)) {
            return null;
        }

        $this->attempts->begin($flowId, $legacy['transaction_id'], [
            'status' => (string) ($legacy['status'] ?? 'pending'),
            'application_status' => (string) ($legacy['application_status'] ?? $legacy['status'] ?? 'pending'),
            'rejection_reasons' => (array) ($legacy['rejection_reasons'] ?? []),
        ]);

        return $this->attempts->active($flowId);
    }

    /**
     * @param  array<string, mixed>  $kycData
     */
    private function completeApprovedAttempt(
        string $flowId,
        int $stepIndex,
        string $transactionId,
        array $kycData,
    ): void {
        Cache::lock("kyc_completion.{$flowId}", 10)->block(5, function () use ($flowId, $stepIndex, $transactionId, $kycData): void {
            if (! $this->attempts->isActive($flowId, $transactionId)) {
                return;
            }

            $flattened = $this->flattenKYCData($kycData);
            $flattened['_step_name'] = 'kyc_verification';
            $this->flows->updateStepData($flowId, $stepIndex, $flattened);

            Cache::put("kyc_completed.{$flowId}", [
                'step_index' => $stepIndex,
                'kyc_data' => $flattened,
                'completed_at' => now()->toIso8601String(),
            ], now()->addMinutes(10));

            Log::info('[KYCFlowController] KYC step completed', [
                'flow_id' => $flowId,
                'step_index' => $stepIndex,
                'transaction_id' => $transactionId,
                'status' => 'approved',
            ]);
        });
    }

    /** @param array<string, mixed>|null $flowState */
    private function restoreFlowStateWhenMissing(string $flowId, ?array $flowState): void
    {
        if ($this->flows->flowExists($flowId) || ! is_array($flowState)) {
            return;
        }

        $flowState['completed_steps'] ??= [];
        $flowState['collected_data'] ??= [];
        $flowState['current_step'] ??= 0;
        Session::put("form_flow.{$flowId}", $flowState);
    }

    private function statusPage(
        string $flowId,
        string $transactionId,
        string $initialStatus,
        bool $canRetry,
    ): InertiaResponse {
        return Inertia::render('form-flow/kyc/KYCStatusPage', [
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'initial_status' => $initialStatus,
            'can_retry' => $canRetry,
            'status_url' => route('form-flow.kyc.status', ['flow_id' => $flowId]),
            'retry_url' => route('form-flow.kyc.retry', ['flow_id' => $flowId]),
            'continue_url' => route('form-flow.show', ['flow_id' => $flowId]),
            'polling_interval' => max(2, (int) config('kyc-handler.polling_interval', 5)),
            'ui_variant' => config('form-flow.ui.variant', 'default'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    private function statusPayload(string $flowId, array $attempt): array
    {
        return [
            'status' => $attempt['status'] ?? 'processing',
            'application_status' => $attempt['application_status'] ?? null,
            'transaction_id' => $attempt['transaction_id'] ?? null,
            'completed_at' => $attempt['completed_at'] ?? null,
            'rejection_reasons' => $attempt['rejection_reasons'] ?? [],
            'can_retry' => $this->attempts->canRetry($attempt),
            'attempt_count' => $this->attempts->attemptCount($flowId),
            'retry_url' => route('form-flow.kyc.retry', ['flow_id' => $flowId]),
            'continue_url' => route('form-flow.show', ['flow_id' => $flowId]),
        ];
    }

    /**
     * @param  array<string, mixed>  $kycData
     * @return array<string, mixed>
     */
    private function flattenKYCData(array $kycData): array
    {
        $idCardDetails = [];
        $idCardImageUrl = null;
        $idCardCroppedImageUrl = null;
        $selfieImageUrl = null;

        foreach ($kycData['modules'] ?? [] as $module) {
            if (in_array($module['module'] ?? null, ['id_card', 'ID Card Validation', 'ID Card Validation front'], true)) {
                $idCardDetails = (array) ($module['details'] ?? []);
                $idCardImageUrl = $module['imageUrl'] ?? null;
                $idCardCroppedImageUrl = $module['croppedImageUrl'] ?? null;
            }

            if (in_array($module['module'] ?? null, ['selfie', 'Selfie Validation'], true)) {
                $selfieImageUrl = $module['imageUrl'] ?? null;
            }
        }

        return [
            'transaction_id' => $kycData['transaction_id'] ?? null,
            'status' => $kycData['status'] ?? null,
            'application_status' => $kycData['application_status'] ?? null,
            'name' => $idCardDetails['full_name'] ?? $idCardDetails['fullName'] ?? null,
            'date_of_birth' => $idCardDetails['date_of_birth'] ?? $idCardDetails['dateOfBirth'] ?? null,
            'address' => $idCardDetails['address'] ?? null,
            'id_number' => $idCardDetails['id_number'] ?? $idCardDetails['idNumber'] ?? null,
            'id_type' => $idCardDetails['id_type'] ?? $idCardDetails['idType'] ?? null,
            'nationality' => $idCardDetails['nationality'] ?? null,
            'id_card_full' => $idCardImageUrl,
            'id_card_cropped' => $idCardCroppedImageUrl,
            'selfie' => $selfieImageUrl,
        ];
    }
}
