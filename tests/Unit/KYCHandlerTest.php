<?php

use LBHurtado\FormHandlerKYC\KYCHandler;
use LBHurtado\FormFlowManager\Contracts\FormHandlerInterface;

test('implements FormHandlerInterface', function () {
    $handler = new KYCHandler();
    expect($handler)->toBeInstanceOf(FormHandlerInterface::class);
});

test('returns correct handler name', function () {
    $handler = new KYCHandler();
    expect($handler->getName())->toBe('kyc');
});

test('config schema includes title and description', function () {
    $handler = new KYCHandler();
    $schema = $handler->getConfigSchema();
    
    expect($schema)->toHaveKey('title')
        ->and($schema)->toHaveKey('description');
});

test('handler auto-registers with form-flow-manager', function () {
    $handlers = config('form-flow.handlers', []);
    
    expect($handlers)->toHaveKey('kyc')
        ->and($handlers['kyc'])->toBe(KYCHandler::class);
});

test('validate always returns true', function () {
    $handler = new KYCHandler();
    expect($handler->validate([], []))->toBeTrue();
});
