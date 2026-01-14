# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Commands

### Testing
```bash
# Run all tests
composer test
# or
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/SomeTest.php

# Run with coverage (requires xdebug)
vendor/bin/pest --coverage
```

### Installation
```bash
# Install dependencies
composer install

# Publish Vue components to parent app
php artisan kyc-handler:install

# Publish Vue components (force overwrite)
php artisan kyc-handler:install --force

# Publish config only
php artisan vendor:publish --tag=kyc-handler-config
```

### Development Workflow
```bash
# After making changes to Vue components in stubs/, publish them:
php artisan kyc-handler:install --force

# Then rebuild frontend assets in parent app:
cd ../.. && npm run build
```

## Architecture

### Package Type
This is a **Laravel package** (not a standalone application) that provides a **form handler plugin** for the Form Flow Manager system (`3neti/form-flow`). It integrates with the HyperVerge KYC API via the `3neti/hyperverge` package.

### Core Components

#### Handler Registration
- **KYCHandler** (`src/KYCHandler.php`): Main handler implementing `FormHandlerInterface`
- **KYCHandlerServiceProvider**: Auto-registers the handler with form-flow-manager on boot
- Handler name: `kyc`

#### External Redirect Flow
Unlike typical form handlers (form, splash, signature), KYC involves:
1. User initiates → redirects to external HyperVerge mobile app
2. User completes verification externally
3. HyperVerge callbacks to app
4. App polls for async results
5. Step auto-completes on approval

#### Key Actions (Lorisleiva)
- **InitiateKYC**: Generates HyperVerge onboarding link, stores context in cache
- **FetchKYCResult**: Polls HyperVerge API for verification status

#### State Management Strategy
- **Session**: Primary storage for flow state (`form_flow.{flow_id}`)
- **Cache**: Bridge for callback context (survives external redirect)
  - `kyc_context.{transaction_id}`: Flow context for callback lookup
  - `kyc_completed.{flow_id}`: Completion signal for browser polling
- **Critical**: Callback runs in different session than user's browser

#### Routes
Registered in `routes/kyc.php`:
- `POST /form-flow/{flow_id}/kyc/initiate`: Start KYC, redirect to HyperVerge
- `GET /form-flow/kyc/callback`: Global callback (uses transactionId query param)
- `GET /form-flow/{flow_id}/kyc/status`: AJAX polling endpoint

#### Data Flattening
KYC results are flattened for Phase 2 variable substitution:
- `modules[].details` → flat key-value pairs
- Example: `modules[id_card].details.full_name` → `name`

#### Fake Mode
Set `KYC_USE_FAKE=true` to bypass HyperVerge and return mock approved data:
- No external redirect
- Instant approval
- Uses same flow structure as real mode

### Frontend (Inertia.js + Vue)
Vue components in `stubs/resources/js/pages/form-flow/kyc/`:
- **KYCInitiatePage.vue**: Start button, mobile display, fake mode support
- **KYCStatusPage.vue**: Polling page after callback (5-second intervals)

Published to parent app via `kyc-handler:install` command.

### Dependencies
- **form-flow-manager**: Parent orchestration system
- **hyperverge**: HyperVerge API client
- **spatie/laravel-data**: Type-safe data DTOs
- **lorisleiva/laravel-actions**: Action pattern
- **inertiajs/inertia-laravel**: SPA frontend

### Testing
- **Pest PHP** test framework
- **Orchestra Testbench** for package testing
- Tests in `tests/Unit/` use `TestCase` which configures service providers
- No feature tests (requires full form-flow integration)

### Configuration
File: `config/kyc-handler.php`
- `use_fake`: Toggle fake mode (default: false)
- `hyperverge.*`: API credentials (delegated to 3neti/hyperverge)
- `polling_interval`: Status polling frequency (default: 5 seconds)
- `auto_redirect_delay`: Delay before redirect after approval (default: 2 seconds)

### Environment Variables
```env
# HyperVerge API (required for real mode)
HYPERVERGE_BASE_URL=https://ind.idv.hyperverge.co/v1
HYPERVERGE_APP_ID=your_app_id
HYPERVERGE_APP_KEY=your_app_key
HYPERVERGE_URL_WORKFLOW=onboarding

# KYC Handler
KYC_USE_FAKE=false
KYC_POLLING_INTERVAL=5
KYC_AUTO_REDIRECT_DELAY=2
```

### Namespace
`LBHurtado\FormHandlerKYC`

## Important Notes
- This package publishes Vue components to the parent app, not standalone
- The handler auto-registers on boot (no manual registration needed)
- Cache is used as a "bridge" because callback runs in a different session
- Transaction IDs use hyphens, not dots (HyperVerge requirement)
- The `current_step` in flow state must match `step_index` for `updateStepData()` to advance correctly
