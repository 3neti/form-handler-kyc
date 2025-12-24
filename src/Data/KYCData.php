<?php

declare(strict_types=1);

namespace LBHurtado\FormHandlerKYC\Data;

use Spatie\LaravelData\Data;

/**
 * KYC Data
 * 
 * Represents KYC verification status and metadata.
 */
class KYCData extends Data
{
    public function __construct(
        public string $transaction_id,
        public string $status,              // approved, pending, processing, rejected, needs_review
        public ?string $onboarding_url,
        public bool $needs_redirect,
        public ?string $completed_at,
        public ?array $rejection_reasons,
    ) {}
}
