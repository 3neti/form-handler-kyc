<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Http\Controllers;

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
use LBHurtado\FormHandlerKYC\Actions\FetchKYCResult;
use LBHurtado\FormHandlerKYC\Actions\InitiateKYC;

/**
 * Handle KYC flow within form flow system.
 */
class KYCFlowController extends Controller
{
    /**
     * Initiate KYC verification for the contact in form flow.
     */
    public function initiate(Request $request, string $flow_id): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        Log::info('[KYCFlowController::initiate] Starting KYC initiation', [
            'flow_id' => $flow_id,
            'request_data' => $request->all(),
        ]);
        
        // Get mobile and step_index from request
        $mobile = $request->input('mobile');
        $country = $request->input('country', 'PH');
        $stepIndex = (int) $request->input('step_index', 0);
        
        if (!$mobile) {
            Log::error('[KYCFlowController] No mobile provided', [
                'flow_id' => $flow_id,
            ]);

            return redirect()
                ->back()
                ->with('error', 'Mobile number is required for KYC verification.');
        }

        // Check if already completed in session
        $existingStatus = Session::get("form_flow.{$flow_id}.kyc.status");
        if ($existingStatus === 'approved') {
            Log::info('[KYCFlowController] KYC already approved', [
                'flow_id' => $flow_id,
            ]);

            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('success', 'Identity already verified!');
        }

        // Generate onboarding link
        try {
            $kycData = InitiateKYC::run($flow_id, $mobile, $country);

            // Store KYC data in session
            Session::put("form_flow.{$flow_id}.kyc", $kycData);
            
            // CRITICAL: Store flow context AND state in cache (survives redirect)
            $flowService = app(\LBHurtado\FormFlowManager\Services\FormFlowService::class);
            $flowState = $flowService->getFlowState($flow_id);
            
            // FIX: Ensure current_step matches the step user is on when they initiated KYC
            // This is crucial for updateStepData() to advance correctly (it checks current_step === stepIndex)
            if ($flowState) {
                $flowState['current_step'] = $stepIndex;
            }
            
            Cache::put("kyc_context.{$kycData['transaction_id']}", [
                'flow_id' => $flow_id,
                'step_index' => $stepIndex,
                'mobile' => $mobile,
                'country' => $country,
                'flow_state' => $flowState, // Store entire flow state
                'initiated_at' => now()->toIso8601String(),
            ], now()->addHours(24));
            
            Log::debug('[KYCFlowController] Flow context stored in cache', [
                'flow_id' => $flow_id,
                'transaction_id' => $kycData['transaction_id'],
            ]);

            Log::info('[KYCFlowController] Redirecting to HyperVerge', [
                'flow_id' => $flow_id,
                'transaction_id' => $kycData['transaction_id'],
            ]);

            // Check if fake mode - use regular redirect for internal URLs
            if (config('kyc-handler.use_fake', false)) {
                return redirect($kycData['onboarding_url']);
            }
            
            // Use Inertia location for external redirect (HyperVerge)
            return Inertia::location($kycData['onboarding_url']);
        } catch (\Exception $e) {
            Log::error('[KYCFlowController] Failed to initiate KYC', [
                'flow_id' => $flow_id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Failed to start KYC verification. Please try again.');
        }
    }

    /**
     * Handle callback from HyperVerge after user completes KYC.
     * Uses transactionId from query params to find flow_id.
     */
    public function callback(Request $request): InertiaResponse|RedirectResponse|Response
    {
        // Get params from HyperVerge callback
        $callbackStatus = $request->query('status');
        $transactionId = $request->query('transactionId');
        
        Log::info('[KYCFlowController] KYC callback received', [
            'transaction_id' => $transactionId,
            'status' => $callbackStatus,
        ]);
        
        // Retrieve flow context from cache by transaction_id (like stash pattern)
        $context = Cache::get("kyc_context.{$transactionId}");
        
        if (!$context) {
            Log::error('[KYCFlowController] No context found for transaction_id', [
                'transaction_id' => $transactionId,
            ]);
            return response('Transaction context not found. Please restart KYC.', 404);
        }
        
        $flow_id = $context['flow_id'];
        $stepIndex = $context['step_index'];
        $flowState = $context['flow_state'];
        
        
        // CRITICAL: Only restore flow state if session is completely lost
        // Do NOT restore if session already exists, as it may have newer data
        $flowService = app(\LBHurtado\FormFlowManager\Services\FormFlowService::class);
        $sessionExists = $flowService->flowExists($flow_id);
        
        if (!$sessionExists) {
            // Ensure flow state has all required keys
            $flowState['completed_steps'] = $flowState['completed_steps'] ?? [];
            $flowState['collected_data'] = $flowState['collected_data'] ?? [];
            $flowState['current_step'] = $flowState['current_step'] ?? 0;
            
            Session::put("form_flow.{$flow_id}", $flowState);
            
            Log::debug('[KYCFlowController] Flow state restored from cache', [
                'flow_id' => $flow_id,
            ]);
        }
        
        // Fetch KYC results from HyperVerge
        try {
            $kycData = FetchKYCResult::run($transactionId);
        } catch (\Exception $e) {
            Log::error('[KYCFlowController] Failed to fetch KYC results', [
                'flow_id' => $flow_id,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('error', 'Failed to fetch KYC results.');
        }

        
        // Complete the step via FormFlowService
        try {
            $flowService = app(\LBHurtado\FormFlowManager\Services\FormFlowService::class);
            $flowService->updateStepData($flow_id, $stepIndex, $kycData);
            
            // CRITICAL: Store completed step in cache for browser session to pick up
            // This is necessary because callback runs in different session than user's browser
            Cache::put("kyc_completed.{$flow_id}", [
                'step_index' => $stepIndex,
                'kyc_data' => $kycData,
                'completed_at' => now()->toIso8601String(),
            ], now()->addMinutes(10));
            
            Log::info('[KYCFlowController] KYC step completed', [
                'flow_id' => $flow_id,
                'step_index' => $stepIndex,
                'status' => $kycData['status'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            Log::error('[KYCFlowController] Failed to complete step', [
                'flow_id' => $flow_id,
                'step_index' => $stepIndex,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('error', 'Failed to complete KYC step.');
        }
        
        // Handle different statuses
        if ($callbackStatus === 'user_cancelled') {
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('error', 'Identity verification was cancelled.');
        }
        
        if ($kycData['status'] === 'approved' || $callbackStatus === 'auto_approved') {
            // KYC approved - continue to next step
            return redirect()
                ->route('form-flow.show', ['flow_id' => $flow_id])
                ->with('success', 'Identity verified successfully!');
        }
        
        // Still processing - show status page
        return Inertia::render('form-flow/kyc/KYCStatusPage', [
            'flow_id' => $flow_id,
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Check KYC status for polling (AJAX endpoint).
     */
    public function status(Request $request, string $flow_id): JsonResponse
    {
        // Get KYC data from cache (fallback to session for user's browser)
        $kycData = Cache::get("kyc.{$flow_id}") ?? Session::get("form_flow.{$flow_id}.kyc", []);

        if (empty($kycData)) {
            return response()->json([
                'status' => null,
                'error' => 'KYC data not found',
            ], 400);
        }

        // Fetch latest results if still processing
        if (in_array($kycData['status'] ?? '', ['pending', 'processing'])) {
            try {
                $result = FetchKYCResult::run($kycData['transaction_id']);
                
                // Update session with results
                $kycData = array_merge($kycData, $result);
                Session::put("form_flow.{$flow_id}.kyc", $kycData);
            } catch (\Exception $e) {
                Log::debug('[KYCFlowController] Results not ready yet', [
                    'flow_id' => $flow_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::debug('[KYCFlowController] Status check', [
            'flow_id' => $flow_id,
            'status' => $kycData['status'] ?? 'unknown',
        ]);

        return response()->json([
            'status' => $kycData['status'] ?? 'pending',
            'transaction_id' => $kycData['transaction_id'] ?? null,
            'completed_at' => $kycData['completed_at'] ?? null,
            'rejection_reasons' => $kycData['rejection_reasons'] ?? [],
        ]);
    }
}
