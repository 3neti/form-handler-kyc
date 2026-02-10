<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use LBHurtado\FormFlowManager\Contracts\FormHandlerInterface;
use LBHurtado\FormFlowManager\Data\FormFlowStepData;
use LBHurtado\FormHandlerKYC\Actions\FetchKYCResult;

/**
 * KYC Handler
 * 
 * Handles identity verification using HyperVerge.
 * Unlike other handlers, KYC involves external redirect and async results.
 */
class KYCHandler implements FormHandlerInterface
{
    public function getName(): string
    {
        return 'kyc';
    }
    
    public function handle(Request $request, FormFlowStepData $step, array $context = []): array
    {
        // Extract data from 'data' key (from form submission in fake mode)
        $inputData = $request->input('data', $request->all());
        
        \Log::info('[KYCHandler] Processing KYC submission', [
            'fake_mode' => config('kyc-handler.use_fake', false),
            'has_mobile' => isset($inputData['mobile']),
        ]);
        
        // In fake mode, return simulated KYC data immediately
        if (config('kyc-handler.use_fake', false) && isset($inputData['mobile'])) {
            \Log::info('[KYCHandler] 🎭 FAKE MODE - Returning simulated KYC data');
            
            $mockData = [
                'transaction_id' => 'MOCK-KYC-' . time(),
                'status' => 'approved',
                'application_status' => 'auto_approved',
                'completed_at' => now()->toIso8601String(),
                'mobile' => $inputData['mobile'],
                'country' => $inputData['country'] ?? 'PH',
                'modules' => [
                    [
                        'module' => 'selfie',
                        'status' => 'auto_approved',
                        'module_id' => 'mock-selfie-001',
                        'details' => [
                            'face_match_score' => 97,
                            'liveness_check' => 'passed',
                        ],
                    ],
                    [
                        'module' => 'id_card',
                        'status' => 'auto_approved',
                        'module_id' => 'mock-id-001',
                        'details' => [
                            'id_type' => 'National ID',
                            'id_number' => 'N01-87-049586',
                            'full_name' => 'HURTADO LESTER BIADORA',
                            'date_of_birth' => '1970-04-21',
                            'nationality' => 'Filipino',
                            'address' => '123 Main Street, Quezon City, Metro Manila, Philippines',
                        ],
                    ],
                ],
            ];
            
            // Flatten for Phase 2 variables
            return $this->flattenKYCData($mockData);
        }
        
        // Real mode: This should not be called directly in real mode
        // The callback completes the step programmatically
        throw new \RuntimeException('KYC handler should not be called directly in real mode. Use /kyc/initiate endpoint.');
    }
    
    public function validate(array $data, array $rules): bool
    {
        // KYC validation happens externally via HyperVerge
        return true;
    }
    
    public function render(FormFlowStepData $step, array $context = [])
    {
        $flowId = $context['flow_id'] ?? null;
        
        if (!$flowId) {
            throw new \RuntimeException('Flow ID required for KYC handler');
        }
        
        // Extract mobile from collected data
        $flowService = app(\LBHurtado\FormFlowManager\Services\FormFlowService::class);
        $collectedData = $flowService->getCollectedData($flowId);
        
        // Flatten collected data
        $flatData = [];
        foreach ($collectedData as $stepData) {
            if (is_array($stepData)) {
                $flatData = array_merge($flatData, $stepData);
            }
        }
        
        // Look for mobile field
        $mobile = $flatData['mobile'] ?? $flatData['phone'] ?? $flatData['mobile_number'] ?? null;
        $country = 'PH';
        
        // Get KYC status from session
        $kycData = Session::get("form_flow.{$flowId}.kyc", []);
        $kycStatus = $kycData['status'] ?? null;
        
        return Inertia::render('form-flow/kyc/KYCInitiatePage', [
            'flow_id' => $flowId,
            'step' => (string) ($context['step_index'] ?? 0),
            'config' => array_merge($step->config, [
                'use_fake' => config('kyc-handler.use_fake', false),
            ]),
            'kyc_status' => $kycStatus,
            'mobile' => $mobile,
            'country' => $country,
        ]);
    }
    
    public function getConfigSchema(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ];
    }
    
    /**
     * Flatten KYC data for Phase 2 variables
     * Extracts id_card details and returns flat array
     */
    protected function flattenKYCData(array $kycData): array
    {
        $idCardDetails = null;
        $idCardImageUrl = null;
        $idCardCroppedImageUrl = null;
        $selfieImageUrl = null;
        
        // Find id_card and selfie modules
        foreach ($kycData['modules'] ?? [] as $module) {
            if (in_array($module['module'], ['id_card', 'ID Card Validation', 'ID Card Validation front'])) {
                $idCardDetails = $module['details'] ?? [];
                $idCardImageUrl = $module['imageUrl'] ?? null;
                $idCardCroppedImageUrl = $module['croppedImageUrl'] ?? null;
            }
            if (in_array($module['module'], ['selfie', 'Selfie Validation'])) {
                $selfieImageUrl = $module['imageUrl'] ?? null;
            }
        }
        
        return [
            'transaction_id' => $kycData['transaction_id'] ?? null,
            'status' => $kycData['status'] ?? null,
            'name' => $idCardDetails['full_name'] ?? $idCardDetails['fullName'] ?? null,
            'date_of_birth' => $idCardDetails['date_of_birth'] ?? $idCardDetails['dateOfBirth'] ?? null,
            'address' => $idCardDetails['address'] ?? null,
            'id_number' => $idCardDetails['id_number'] ?? $idCardDetails['idNumber'] ?? null,
            'id_type' => $idCardDetails['id_type'] ?? $idCardDetails['idType'] ?? null,
            'nationality' => $idCardDetails['nationality'] ?? null,
            // Image URLs from HyperVerge
            'id_card_full' => $idCardImageUrl,
            'id_card_cropped' => $idCardCroppedImageUrl,
            'selfie' => $selfieImageUrl,
        ];
    }
}
