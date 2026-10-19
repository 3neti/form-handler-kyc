<?php

use Illuminate\Http\Request;
use LBHurtado\FormFlowManager\Data\FormFlowStepData;
use LBHurtado\FormFlowManager\Services\FormFlowPresentationResolver;
use LBHurtado\FormHandlerKYC\KYCHandler;

it('keeps the resolved presentation identical in live and inert preview responses', function () {
    $policy = app(FormFlowPresentationResolver::class)->resolve(['brand' => ['name' => 'Synthetic Brand']]);
    $step = FormFlowStepData::from(['handler' => 'kyc', 'config' => ['presentation' => $policy]]);
    $handler = app(KYCHandler::class);
    $preview = $handler->preview($step, ['flow_id' => 'synthetic', 'step_index' => 0]);
    $request = Request::create('/synthetic', 'GET', server: ['HTTP_X_INERTIA' => 'true']);
    $live = $handler->render($step, ['flow_id' => 'synthetic', 'step_index' => 0])->toResponse($request)->getData(true);
    expect($preview['props']['presentation'])->toBe($policy)
        ->and($live['props']['presentation'])->toBe($policy)
        ->and($preview['props']['preview_mode'])->toBeTrue();
});
