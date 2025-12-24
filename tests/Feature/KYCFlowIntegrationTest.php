<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use LBHurtado\FormFlowManager\Services\FormFlowService;
use LBHurtado\FormHandlerKYC\Actions\FetchKYCResult;
use LBHurtado\FormHandlerKYC\Actions\InitiateKYC;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Clear session before each test
    Session::flush();
});

test('fake mode: complete flow Form → KYC → Location', function () {
    // Enable fake mode
    Config::set('kyc-handler.use_fake', true);
    
    // Create a form flow with 3 steps
    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-flow-' . time(),
        'steps' => [
            ['handler' => 'form', 'config' => ['fields' => [['name' => 'mobile', 'type' => 'text']]]],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'location', 'config' => []],
        ],
        'callbacks' => [
            'on_complete' => 'http://example.com/callback',
        ],
    ];
    
    $state = $flowService->startFlow(\LBHurtado\FormFlowManager\Data\FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];
    
    // Step 1: Complete form step
    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['mobile' => '09173011987'],
    ])->assertRedirect("/form-flow/{$flowId}");
    
    // Verify step 0 completed
    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(1);
    expect($state['collected_data'][0])->toHaveKey('mobile');
    
    // Step 2: Complete KYC step (fake mode)
    $this->post("/form-flow/{$flowId}/step/1", [
        'data' => ['mobile' => '09173011987', 'country' => 'PH'],
    ])->assertRedirect("/form-flow/{$flowId}");
    
    // Verify step 1 completed with mock KYC data
    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(2);
    expect($state['collected_data'][1])->toHaveKeys(['status', 'modules', 'transaction_id']);
    expect($state['collected_data'][1]['status'])->toBe('approved');
    
    // Step 3: Complete location step
    $this->post("/form-flow/{$flowId}/step/2", [
        'data' => ['latitude' => 14.5995, 'longitude' => 120.9842],
    ])->assertRedirect("/form-flow/{$flowId}");
    
    // Verify flow completed
    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(3);
    expect($state['status'])->toBe('completed');
});

test('real mode: initiate stores step_index in session', function () {
    Config::set('kyc-handler.use_fake', false);
    
    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-real-' . time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];
    
    $state = $flowService->startFlow(\LBHurtado\FormFlowManager\Data\FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];
    
    // Complete form step first
    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['mobile' => '09173011987'],
    ]);
    
    // Mock HyperVerge response to avoid actual API call
    $this->mock(InitiateKYC::class, function ($mock) {
        $mock->shouldReceive('handle')
            ->once()
            ->andReturn([
                'transaction_id' => 'test-transaction-123',
                'onboarding_url' => 'http://hyperverge.test/kyc',
                'mobile' => '09173011987',
                'country' => 'PH',
                'status' => 'pending',
            ]);
    });
    
    // Initiate KYC
    $this->post("/form-flow/{$flowId}/kyc/initiate", [
        'mobile' => '09173011987',
        'country' => 'PH',
        'step_index' => 1,
    ]);
    
    // Verify step_index stored in session
    expect(Session::get("form_flow.{$flowId}.kyc.step_index"))->toBe(1);
});

test('real mode: callback completes step via FormFlowService', function () {
    Config::set('kyc-handler.use_fake', true); // Use fake for FetchKYCResult
    
    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-callback-' . time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
            ['handler' => 'location', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];
    
    $state = $flowService->startFlow(\LBHurtado\FormFlowManager\Data\FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];
    
    // Complete form step
    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['mobile' => '09173011987'],
    ]);
    
    // Simulate initiate storing step_index
    Session::put("form_flow.{$flowId}.kyc.step_index", 1);
    
    // Create transaction ID format: formflow-{flow_id}-{timestamp}
    $cleanFlowId = str_replace('.', '-', $flowId);
    $transactionId = "formflow-{$cleanFlowId}-" . time();
    
    // Call callback (simulating HyperVerge redirect)
    $this->get("/form-flow/kyc/callback?transactionId={$transactionId}&status=auto_approved")
        ->assertRedirect("/form-flow/{$flowId}");
    
    // Verify step 1 completed
    $state = $flowService->getFlowState($flowId);
    expect($state['current_step'])->toBe(2); // Advanced to next step
    expect($state['collected_data'][1])->toHaveKey('status');
    expect($state['collected_data'][1]['status'])->toBe('approved');
});

test('callback handles missing step_index gracefully', function () {
    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-missing-step-' . time(),
        'steps' => [['handler' => 'kyc', 'config' => []]],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];
    
    $state = $flowService->startFlow(\LBHurtado\FormFlowManager\Data\FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];
    
    // Don't store step_index (simulate session expiration)
    
    $cleanFlowId = str_replace('.', '-', $flowId);
    $transactionId = "formflow-{$cleanFlowId}-" . time();
    
    Config::set('kyc-handler.use_fake', true);
    
    // Call callback without step_index
    $this->get("/form-flow/kyc/callback?transactionId={$transactionId}&status=auto_approved")
        ->assertRedirect("/form-flow/{$flowId}")
        ->assertSessionHas('error');
});

test('flow state preserved across redirect', function () {
    Config::set('kyc-handler.use_fake', true);
    
    $flowService = app(FormFlowService::class);
    $instructions = [
        'reference_id' => 'test-kyc-state-' . time(),
        'steps' => [
            ['handler' => 'form', 'config' => []],
            ['handler' => 'kyc', 'config' => []],
        ],
        'callbacks' => ['on_complete' => 'http://example.com/callback'],
    ];
    
    $state = $flowService->startFlow(\LBHurtado\FormFlowManager\Data\FormFlowInstructionsData::from($instructions));
    $flowId = $state['flow_id'];
    
    // Complete form step
    $this->post("/form-flow/{$flowId}/step/0", [
        'data' => ['email' => 'test@example.com', 'name' => 'Test User'],
    ]);
    
    // Store step data before KYC
    $stateBeforeKYC = $flowService->getFlowState($flowId);
    
    // Complete KYC step
    $this->post("/form-flow/{$flowId}/step/1", [
        'data' => ['mobile' => '09173011987'],
    ]);
    
    // Verify previous step data still exists
    $stateAfterKYC = $flowService->getFlowState($flowId);
    expect($stateAfterKYC['collected_data'][0])->toEqual($stateBeforeKYC['collected_data'][0]);
    expect($stateAfterKYC['collected_data'][1])->toHaveKey('status');
});
