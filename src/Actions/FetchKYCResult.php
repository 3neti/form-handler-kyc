<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Actions;

use LBHurtado\HyperVerge\Actions\Results\FetchKYCResult as FetchHyperVergeResult;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Fetch KYC results from HyperVerge.
 * Stateless - returns structured data array.
 */
class FetchKYCResult
{
    use AsAction;

    /**
     * Fetch latest KYC results from HyperVerge.
     * 
     * @param  string  $transactionId  The HyperVerge transaction ID
     * @return array  Structured KYC result data
     * @throws \Exception  If results not ready or API error
     */
    public function handle(string $transactionId): array
    {
        if (empty($transactionId)) {
            throw new \Exception('Transaction ID required');
        }

        \Log::info('[FetchKYCResult] Fetching results', [
            'transaction_id' => $transactionId,
            'fake_mode' => config('kyc-handler.use_fake', false),
        ]);

        // Check if fake mode is enabled
        if (config('kyc-handler.use_fake', false)) {
            \Log::info('[FetchKYCResult] 🎭 FAKE MODE - Returning mock data', [
                'transaction_id' => $transactionId,
            ]);
            
            // Return mock approved KYC data
            return [
                'transaction_id' => $transactionId,
                'status' => 'approved',
                'application_status' => 'auto_approved',
                'modules' => [
                    [
                        'module' => 'selfie',
                        'status' => 'auto_approved',
                        'module_id' => 'mock-selfie-001',
                        'details' => ['face_match_score' => 98],
                    ],
                    [
                        'module' => 'id_card',
                        'status' => 'auto_approved',
                        'module_id' => 'mock-id-001',
                        'details' => [
                            'id_type' => 'National ID',
                            'id_number' => 'MOCK-12345678',
                            'name' => 'Mock User',
                            'date_of_birth' => '1990-01-01',
                        ],
                    ],
                ],
            ];
        }

        // Fetch real results from HyperVerge
        $result = FetchHyperVergeResult::run($transactionId);

        // Map status from applicationStatus (auto_approved, user_cancelled, needs_review)
        $status = match ($result->applicationStatus) {
            'auto_approved' => 'approved',
            'user_cancelled' => 'cancelled',
            'needs_review' => 'pending',
            default => 'processing',
        };

        // Parse and return structured data
        $data = [
            'transaction_id' => $transactionId,
            'status' => $status,
            'application_status' => $result->applicationStatus,
            'modules' => array_map(fn($module) => [
                'module' => $module->module,
                'status' => $module->status,
                'module_id' => $module->moduleId,
                'details' => $module->details,
                // Include image URLs if available
                'imageUrl' => $module->imageUrl ?? null,
                'croppedImageUrl' => $module->croppedImageUrl ?? null,
            ], $result->modules),
        ];

        \Log::info('[FetchKYCResult] Results fetched', [
            'transaction_id' => $transactionId,
            'status' => $data['status'],
        ]);

        return $data;
    }
}
