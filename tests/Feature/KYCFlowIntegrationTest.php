<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use LBHurtado\FormFlowManager\Data\FormFlowInstructionsData;
use LBHurtado\FormFlowManager\Services\FormFlowService;
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
