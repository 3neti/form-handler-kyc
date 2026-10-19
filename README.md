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
composer require 3neti/form-handler-kyc:^1.1
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
- Laravel 12 or 13
- 3neti/form-flow 1.8+
- 3neti/hyperverge 1.1+ or 2.0 beta+
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
composer install
composer test
composer pint -- --test
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

## YAML presentation integration

The expanded tokens/screens/actions contract requires the companion Form Flow
changes in this development slice; it is not provided by the released v1.10.0
alone. Release the manager before its dependent handler versions. The portable
schema, inheritance and limits are documented in `docs/PRESENTATION.md` in
[3neti/form-flow](https://github.com/3neti/form-flow). x-change owns its Pay Code
profile and domain driver; this package owns the handler interaction.

In an existing complete YAML driver, merge this step configuration:

```yaml
steps:
  kyc:
    handler: kyc
    step_name: kyc_step
    title: Identity verification
    config:
      presentation:
        layout:
          density: compact
        actions:
          placement: viewport_bottom
```

The driver may also select a registered profile in top-level `presentation`.
Step settings override driver settings, which override the selected profile
and manager defaults. The handler page uses the shared presentation and action
components; legacy `ui_variant` remains a fallback without that policy.

Both initiation and status pages receive the shared presentation. Status icons and notices consume the shared semantic policy. The external identity provider
page is outside this contract. Keep Start Identity Verification distinct from
Continue; a single step label can override both states. Provider configuration,
verification decisions and callback handling are not presentation settings.

After package updates, publish this handler's Vue resources using its installer
and rebuild the standalone host. In x-change hosts, use
`php artisan x-change:publish --scope=build --force --verify --no-interaction`
and the host build workflow instead. Review intentional host customizations
before forced publication; never maintain fixes in generated host copies.

Verify a fresh fixture-based flow and preview after changes. Active flows retain
their frozen presentation. Test initial, processing, error, and completed/review
states on mobile; do not collect real identity evidence or send real OTPs merely
to verify appearance. Unsupported schema settings must fail validation.
