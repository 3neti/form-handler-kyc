<?php

use LBHurtado\FormFlowManager\Contracts\FormHandlerInterface;
use LBHurtado\FormFlowManager\Contracts\FormHandlerPreviewInterface;
use LBHurtado\FormFlowManager\Data\FormFlowStepData;
use LBHurtado\FormHandlerKYC\KYCHandler;

test('implements FormHandlerInterface', function () {
    $handler = new KYCHandler;
    expect($handler)->toBeInstanceOf(FormHandlerInterface::class);
});

test('previews the production KYC screen in inert mode', function () {
    $preview = (new KYCHandler)->preview(
        FormFlowStepData::from(['handler' => 'kyc', 'config' => []]),
        ['preview_mobile' => '09170000000'],
    );

    expect(new KYCHandler)->toBeInstanceOf(FormHandlerPreviewInterface::class)
        ->and($preview['component'])->toBe('form-flow/kyc/KYCInitiatePage')
        ->and($preview['props']['preview_mode'])->toBeTrue();
});

test('returns correct handler name', function () {
    $handler = new KYCHandler;
    expect($handler->getName())->toBe('kyc');
});

test('config schema includes copy and shared UI variant controls', function () {
    $handler = new KYCHandler;
    $schema = $handler->getConfigSchema();

    expect($schema)->toHaveKey('title')
        ->and($schema)->toHaveKey('description')
        ->and($schema)->toHaveKey('ui_variant')
        ->and($schema)->toHaveKey('action_placement')
        ->and($schema)->toHaveKey('ui_layout')
        ->and($schema)->toHaveKey('app_name')
        ->and($schema)->toHaveKey('app_logo')
        ->and($schema)->toHaveKey('package_versions')
        ->and($schema)->toHaveKey('show_package_versions')
        ->and($schema['ui_variant'])->toContain('default,compact,immersive')
        ->and($schema['action_placement'])->toContain('inline,bottom,bottom_sticky,viewport_bottom');
});

test('handler auto-registers with form-flow-manager', function () {
    $handlers = config('form-flow.handlers', []);

    expect($handlers)->toHaveKey('kyc')
        ->and($handlers['kyc'])->toBe(KYCHandler::class);
});

test('validate always returns true', function () {
    $handler = new KYCHandler;
    expect($handler->validate([], []))->toBeTrue();
});
test('published claim screen fails closed in preview mode', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/stubs/resources/js/pages/form-flow/kyc/KYCInitiatePage.vue');

    expect($source)->toContain('props.preview_mode');
});
