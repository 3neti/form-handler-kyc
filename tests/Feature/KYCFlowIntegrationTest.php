<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use LBHurtado\FormFlowManager\Data\FormFlowInstructionsData;
use LBHurtado\FormFlowManager\Services\FormFlowService;
use LBHurtado\FormHandlerKYC\Actions\FetchKYCResult;
use LBHurtado\FormHandlerKYC\Services\KYCFlowAttemptStore;
use LBHurtado\FormHandlerKYC\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Session::flush();
    Cache::flush();
});

test('fake mode completes a form, KYC, and form flow', function () {
    Config::set('kyc-handler.use_fake', true);
    Http::fake();

    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-flow-'.time(),
        'steps' => [
            ['handler' => 'form', 'config' => ['fields' => [['name' => 'mobile', 'type' => 'text']]]],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'form', 'config' => ['fields' => [['name' => 'address', 'type' => 'text']]]],
        ],
        'callbacks' => [
            'on_complete' => 'http://example.com/callback',
        ],
    ];

    $state = $flowService->startFlow(FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];

    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['mobile' => '09173011987'],
    ])->assertOk();

    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(1);
    expect($state['collected_data'][0])->toHaveKey('mobile');

    $this->post("/form-flow/{$flowId}/step/1", [
        'data' => ['mobile' => '09173011987', 'country' => 'PH'],
    ])->assertOk();

    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(2);
    expect($state['collected_data'][1])->toHaveKeys([
        'status',
        'transaction_id',
        'id_card_full',
        'id_card_cropped',
        'selfie',
    ]);
    expect($state['collected_data'][1]['status'])->toBe('approved');

    $this->post("/form-flow/{$flowId}/step/2", [
        'data' => ['address' => 'Quezon City'],
    ])->assertOk();

    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(3);

    $this->post("/form-flow/{$flowId}/complete")
        ->assertOk()
        ->assertJsonPath('state.status', 'completed');

    $state = $flowService->getFlowState($flowId);
    expect($state['status'])->toBe('completed');
});

test('initiation stores callback context independently of the browser session', function () {
    Config::set('kyc-handler.use_fake', true);

    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-initiate-'.time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];

    $state = $flowService->startFlow(FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];

    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['mobile' => '09173011987'],
    ])->assertOk();

    $this->post("/form-flow/{$flowId}/kyc/initiate", [
        'mobile' => '09173011987',
        'country' => 'PH',
        'step_index' => 1,
    ])->assertRedirect();

    $transactionId = Session::get("form_flow.{$flowId}.kyc.transaction_id");

    expect($transactionId)->toBeString()
        ->and(Cache::get("kyc_context.{$transactionId}"))
        ->toMatchArray([
            'flow_id' => $flowId,
            'step_index' => 1,
            'mobile' => '09173011987',
            'country' => 'PH',
        ]);
});

test('callback completes the KYC step through cached context', function () {
    Config::set('kyc-handler.use_fake', true);

    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-callback-'.time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'form', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];

    $state = $flowService->startFlow(FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];

    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['mobile' => '09173011987'],
    ])->assertOk();

    $this->post("/form-flow/{$flowId}/kyc/initiate", [
        'mobile' => '09173011987',
        'country' => 'PH',
        'step_index' => 1,
    ])->assertRedirect();

    $transactionId = Session::get("form_flow.{$flowId}.kyc.transaction_id");

    $this->get("/form-flow/kyc/callback?transactionId={$transactionId}&status=auto_approved")
        ->assertRedirect("/form-flow/{$flowId}");

    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(2);
    expect($state['collected_data'][1])->toHaveKey('status');
    expect($state['collected_data'][1]['status'])->toBe('approved');
});

test('callback fails closed when its initiation context is missing', function () {
    Config::set('kyc-handler.use_fake', true);

    $this->get('/form-flow/kyc/callback?transactionId=unknown&status=auto_approved')
        ->assertNotFound()
        ->assertSee('Transaction context not found');
});

test('flow state preserved across redirect', function () {
    Config::set('kyc-handler.use_fake', true);

    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-state-'.time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];

    $state = $flowService->startFlow(FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];

    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['email' => 'test@example.com', 'name' => 'Test User'],
    ])->assertOk();

    $stateBeforeKYC = $flowService->getFlowState($flowId);

    $this->post("/form-flow/{$flowId}/step/1", [
        'data' => ['mobile' => '09173011987'],
    ])->assertOk();

    $stateAfterKYC = $flowService->getFlowState($flowId);
    expect($stateAfterKYC['collected_data'][0])->toEqual($stateBeforeKYC['collected_data'][0]);
    expect($stateAfterKYC['collected_data'][1])->toHaveKey('status');
});

test('needs review remains incomplete and is available across browser sessions', function () {
    Config::set('kyc-handler.use_fake', false);
    Config::set('hyperverge.app_id', 'test-app');
    Config::set('hyperverge.app_key', 'test-key');
    Config::set('hyperverge.url_workflow', 'onboarding');

    Http::fake([
        '*/link-kyc/results' => Http::response([
            'status' => 'success',
            'statusCode' => '200',
            'metadata' => [
                'requestId' => 'request-review',
                'transactionId' => 'transaction-review',
            ],
            'result' => [
                'applicationStatus' => 'needs_review',
                'results' => [],
            ],
        ]),
    ]);

    $flowService = app(FormFlowService::class);
    $state = $flowService->startFlow(FormFlowInstructionsData::from([
        'reference_id' => 'test-kyc-review-'.time(),
        'presentation' => ['brand' => ['name' => 'Synthetic Claim']],
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'form', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ]));
    $flowId = $state['flow_id'];
    $state['current_step'] = 1;
    Session::put("form_flow.{$flowId}", $state);

    app(KYCFlowAttemptStore::class)->begin($flowId, 'transaction-review');
    Cache::put('kyc_context.transaction-review', [
        'flow_id' => $flowId,
        'step_index' => 1,
        'mobile' => '09173011987',
        'country' => 'PH',
        'flow_state' => $state,
    ]);

    Session::flush();

    expect(FetchKYCResult::run('transaction-review')['status'])->toBe('needs_review');

    $this->withHeader('X-Inertia', 'true')
        ->get('/form-flow/kyc/callback?transactionId=transaction-review&status=needs_review')
        ->assertSuccessful()
        ->assertJsonPath('component', 'form-flow/kyc/KYCStatusPage')
        ->assertJsonPath('props.presentation.brand.name', 'Synthetic Claim')
        ->assertJsonPath('props.presentation.progress.current', 2);

    $this->getJson("/form-flow/{$flowId}/kyc/status")
        ->assertSuccessful()
        ->assertJsonPath('status', 'needs_review')
        ->assertJsonPath('can_retry', true)
        ->assertJsonPath('attempt_count', 1);

    expect(Cache::get("kyc_completed.{$flowId}"))->toBeNull()
        ->and(app(KYCFlowAttemptStore::class)->active($flowId)['status'])
        ->toBe('needs_review');
});

test('provider transaction absence remains pending during the consistency grace period', function () {
    Config::set('kyc-handler.use_fake', false);
    Config::set('kyc-handler.provider_result_grace_seconds', 120);
    Config::set('hyperverge.app_id', 'test-app');
    Config::set('hyperverge.app_key', 'test-key');
    Carbon::setTestNow('2026-08-05 12:00:00');

    Http::fake([
        '*/link-kyc/results' => Http::response([
            'status' => 'failure',
            'statusCode' => 422,
            'result' => ['error' => 'TransactionId not found'],
        ], 422),
    ]);

    $flowService = app(FormFlowService::class);
    $state = $flowService->startFlow(FormFlowInstructionsData::from([
        'reference_id' => 'test-provider-grace',
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ]));
    $flowId = $state['flow_id'];
    $state['current_step'] = 1;
    Session::put("form_flow.{$flowId}", $state);

    app(KYCFlowAttemptStore::class)->begin($flowId, 'transaction-eventual');

    Carbon::setTestNow('2026-08-05 12:01:00');

    $this->getJson("/form-flow/{$flowId}/kyc/status")
        ->assertSuccessful()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('can_retry', false);

    expect($flowService->getFlowState($flowId)['current_step'])->toBe(1)
        ->and(Cache::get("kyc_completed.{$flowId}"))->toBeNull();
});

test('provider transaction absence becomes a retryable error after the consistency grace period', function () {
    Config::set('kyc-handler.use_fake', false);
    Config::set('kyc-handler.provider_result_grace_seconds', 120);
    Config::set('hyperverge.app_id', 'test-app');
    Config::set('hyperverge.app_key', 'test-key');
    Carbon::setTestNow('2026-08-05 12:00:00');

    Http::fake([
        '*/link-kyc/results' => Http::response([
            'status' => 'failure',
            'statusCode' => 422,
            'result' => ['error' => 'TransactionId not found'],
        ], 422),
    ]);

    $flowService = app(FormFlowService::class);
    $state = $flowService->startFlow(FormFlowInstructionsData::from([
        'reference_id' => 'test-provider-missing',
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ]));
    $flowId = $state['flow_id'];
    $state['current_step'] = 1;
    Session::put("form_flow.{$flowId}", $state);

    app(KYCFlowAttemptStore::class)->begin($flowId, 'transaction-missing');

    Carbon::setTestNow('2026-08-05 12:02:01');

    $this->getJson("/form-flow/{$flowId}/kyc/status")
        ->assertSuccessful()
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('application_status', 'provider_transaction_unavailable')
        ->assertJsonPath('can_retry', true)
        ->assertJsonPath(
            'rejection_reasons.0',
            'The identity provider could not locate this attempt. Start a new identity check.',
        );

    expect($flowService->getFlowState($flowId)['current_step'])->toBe(1)
        ->and(Cache::get("kyc_completed.{$flowId}"))->toBeNull();
});

test('approved provider status completes only the active attempt', function () {
    Config::set('kyc-handler.use_fake', false);
    Config::set('hyperverge.app_id', 'test-app');
    Config::set('hyperverge.app_key', 'test-key');
    Config::set('hyperverge.url_workflow', 'onboarding');

    Http::fake([
        '*/link-kyc/results' => Http::response([
            'status' => 'success',
            'statusCode' => '200',
            'metadata' => [
                'requestId' => 'request-approved',
                'transactionId' => 'transaction-approved',
            ],
            'result' => [
                'applicationStatus' => 'auto_approved',
                'results' => [],
            ],
        ]),
    ]);

    $flowService = app(FormFlowService::class);
    $state = $flowService->startFlow(FormFlowInstructionsData::from([
        'reference_id' => 'test-kyc-approved-'.time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'form', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ]));
    $flowId = $state['flow_id'];
    $state['current_step'] = 1;
    Session::put("form_flow.{$flowId}", $state);

    app(KYCFlowAttemptStore::class)->begin($flowId, 'transaction-approved');
    Cache::put('kyc_context.transaction-approved', [
        'flow_id' => $flowId,
        'step_index' => 1,
        'mobile' => '09173011987',
        'country' => 'PH',
        'flow_state' => $state,
    ]);

    expect(FetchKYCResult::run('transaction-approved')['status'])->toBe('approved');

    $this->getJson("/form-flow/{$flowId}/kyc/status")
        ->assertSuccessful()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('can_retry', false);

    $freshState = $flowService->getFlowState($flowId);

    expect($freshState['current_step'])->toBe(2)
        ->and($freshState['collected_data'][1]['status'])->toBe('approved')
        ->and(data_get(Cache::get("kyc_completed.{$flowId}"), 'kyc_data.status'))->toBe('approved');
});

test('retry keeps the Pay Code flow and supersedes the prior KYC attempt', function () {
    Config::set('kyc-handler.use_fake', false);
    Config::set('hyperverge.test_mode', true);
    Config::set('hyperverge.app_id', 'test-app');
    Config::set('hyperverge.app_key', 'test-key');
    Config::set('hyperverge.url_workflow', 'onboarding');

    $flowService = app(FormFlowService::class);
    $state = $flowService->startFlow(FormFlowInstructionsData::from([
        'reference_id' => 'pay-code-TEST-9DVZ',
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'form', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ]));
    $flowId = $state['flow_id'];
    $state['current_step'] = 1;
    Session::put("form_flow.{$flowId}", $state);

    $store = app(KYCFlowAttemptStore::class);
    $store->begin($flowId, 'transaction-original');
    $store->record($flowId, 'transaction-original', [
        'status' => 'needs_review',
        'application_status' => 'needs_review',
    ]);
    Cache::put('kyc_context.transaction-original', [
        'flow_id' => $flowId,
        'step_index' => 1,
        'mobile' => '09173011987',
        'country' => 'PH',
        'flow_state' => $state,
    ]);

    $this->post("/form-flow/{$flowId}/kyc/retry")
        ->assertRedirect();

    $attemptState = $store->state($flowId);

    expect($attemptState['active_transaction_id'])->not->toBe('transaction-original')
        ->and($attemptState['attempts'])->toHaveCount(2)
        ->and($attemptState['attempts'][0]['active'])->toBeFalse()
        ->and($attemptState['attempts'][0]['superseded_at'])->not->toBeNull()
        ->and($attemptState['attempts'][1]['active'])->toBeTrue()
        ->and($flowService->getFlowState($flowId)['current_step'])->toBe(1);
});

test('superseded KYC approval cannot advance the claim flow', function () {
    Config::set('kyc-handler.use_fake', false);
    Config::set('hyperverge.app_id', 'test-app');
    Config::set('hyperverge.app_key', 'test-key');
    Config::set('hyperverge.url_workflow', 'onboarding');

    Http::fake([
        '*/link-kyc/results' => Http::response([
            'status' => 'success',
            'statusCode' => '200',
            'metadata' => [
                'requestId' => 'request-old-approved',
                'transactionId' => 'transaction-old',
            ],
            'result' => [
                'applicationStatus' => 'auto_approved',
                'results' => [],
            ],
        ]),
    ]);

    $flowService = app(FormFlowService::class);
    $state = $flowService->startFlow(FormFlowInstructionsData::from([
        'reference_id' => 'test-kyc-superseded-'.time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'form', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ]));
    $flowId = $state['flow_id'];
    $state['current_step'] = 1;
    Session::put("form_flow.{$flowId}", $state);

    $store = app(KYCFlowAttemptStore::class);
    $store->begin($flowId, 'transaction-old');
    Cache::put('kyc_context.transaction-old', [
        'flow_id' => $flowId,
        'step_index' => 1,
        'mobile' => '09173011987',
        'country' => 'PH',
        'flow_state' => $state,
    ]);
    $store->begin($flowId, 'transaction-current');

    $this->withHeader('X-Inertia', 'true')
        ->get('/form-flow/kyc/callback?transactionId=transaction-old&status=auto_approved')
        ->assertSuccessful()
        ->assertJsonPath('component', 'form-flow/kyc/KYCStatusPage')
        ->assertJsonPath('props.initial_status', 'superseded');

    expect($flowService->getFlowState($flowId)['current_step'])->toBe(1)
        ->and(Cache::get("kyc_completed.{$flowId}"))->toBeNull();
});
