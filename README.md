# KYC Handler Plugin

A Form Flow Manager plugin for identity verification using HyperVerge KYC API.

## Features

✅ External redirect to HyperVerge mobile app  
✅ Callback handling from HyperVerge  
✅ Status polling for async verification results  
✅ Contact-level KYC persistence (reusable across flows)  
✅ Auto-registration with Form Flow Manager  
✅ Integration with 3neti/hyperverge package

## Installation

```bash
composer require 3neti/form-handler-kyc
```

## Usage

```javascript
{
    handler: 'kyc',
    config: {
        title: 'Identity Verification',
        description: 'Verify your identity to continue'
    }
}
```

## Configuration

The package uses existing HyperVerge environment variables:

```env
HYPERVERGE_BASE_URL=https://ind.idv.hyperverge.co/v1
HYPERVERGE_APP_ID=your_app_id
HYPERVERGE_APP_KEY=your_app_key
HYPERVERGE_URL_WORKFLOW=onboarding

# Optional
KYC_POLLING_INTERVAL=5  # seconds
KYC_AUTO_REDIRECT_DELAY=2  # seconds
```

## Requirements

- PHP 8.2+
- Laravel 12+
- 3neti/hyperverge package
- HTTPS (required by HyperVerge)
- Mobile device with camera (for user)

## How It Works

Unlike other handlers (location, selfie, signature), KYC involves:

1. **Initiation**: User clicks "Start Identity Verification"
2. **Redirect**: User redirected to HyperVerge mobile app
3. **Verification**: User completes ID + selfie verification
4. **Callback**: HyperVerge redirects back to app
5. **Polling**: Status page polls for results every 5 seconds
6. **Completion**: On approval, flow continues automatically

## Contact-Level KYC

KYC status is stored on the `Contact` model, not per-flow:
- Once verified, contact can reuse KYC across multiple flows
- KYC data stored in `meta` JSON column (schemaless)
- Fields: `kyc_status`, `kyc_transaction_id`, `kyc_onboarding_url`, etc.

## Testing

```bash
cd packages/form-handler-kyc
composer install
vendor/bin/pest
```

## Routes

The package registers these routes automatically:

- `POST /form-flow/{flow_id}/kyc/initiate` - Start KYC flow
- `GET /form-flow/{flow_id}/kyc/callback` - Handle HyperVerge callback
- `GET /form-flow/{flow_id}/kyc/status` - Poll KYC status (AJAX)

## License

MIT

## Author

3neti
