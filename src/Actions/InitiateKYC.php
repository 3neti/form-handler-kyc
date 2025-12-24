<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Actions;

use Illuminate\Support\Facades\Session;
use LBHurtado\HyperVerge\Actions\LinkKYC\GenerateOnboardingLink;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Initiate KYC verification in form flow context.
 * Stateless - returns data for session storage.
 */
class InitiateKYC
{
    use AsAction;

    /**
     * Generate HyperVerge onboarding link.
     * 
     * @param  string  $flowId  The form flow ID
     * @param  string  $mobile  The mobile number
     * @param  string  $country  The country code (default: PH)
     * @return array  KYC initiation data
     */
    public function handle(string $flowId, string $mobile, string $country = 'PH'): array
    {
        // Generate unique transaction ID (use hyphens, avoid dots for HyperVerge)
        $cleanFlowId = str_replace('.', '-', $flowId);
        $transactionId = "formflow-{$cleanFlowId}-" . now()->timestamp;

        // Build redirect URL for callback (simple format - no flow_id in path)
        $redirectUrl = route('form-flow.kyc.callback');

        \Log::info('[InitiateKYC] Generating onboarding link', [
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'mobile' => $mobile,
            'redirect_url' => $redirectUrl,
            'fake_mode' => config('kyc-handler.use_fake', false),
        ]);

        // Check if fake mode is enabled
        if (config('kyc-handler.use_fake', false)) {
            // In fake mode, redirect directly to callback with auto_approved status
            $onboardingUrl = $redirectUrl . '?transactionId=' . urlencode($transactionId) . '&status=auto_approved';
            
            \Log::info('[InitiateKYC] 🎭 FAKE MODE - Skipping HyperVerge', [
                'flow_id' => $flowId,
                'fake_callback_url' => $onboardingUrl,
            ]);
        } else {
            // Generate real HyperVerge onboarding link
            $onboardingUrl = GenerateOnboardingLink::get(
                transactionId: $transactionId,
                redirectUrl: $redirectUrl,
                options: [
                    'validateWorkflowInputs' => 'no',
                    'allowEmptyWorkflowInputs' => 'yes',
                ]
            );
        }

        // Store reverse mapping for callback lookup
        Session::put("kyc_transaction.{$transactionId}", $flowId);

        \Log::info('[InitiateKYC] Onboarding link generated', [
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
        ]);

        return [
            'transaction_id' => $transactionId,
            'onboarding_url' => $onboardingUrl,
            'mobile' => $mobile,
            'country' => $country,
            'status' => 'pending',
        ];
    }
}
