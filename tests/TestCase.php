<?php

namespace LBHurtado\FormHandlerKYC\Tests;

use LBHurtado\FormFlowManager\FormFlowServiceProvider;
use LBHurtado\FormHandlerKYC\KYCHandlerServiceProvider;
use LBHurtado\HyperVerge\HypervergeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            HypervergeServiceProvider::class,
            FormFlowServiceProvider::class,
            KYCHandlerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);

        // Laravel Data configuration
        $app['config']->set('data.validation_strategy', 'only_requests');
        $app['config']->set('data.max_transformation_depth', 6);
        $app['config']->set('data.throw_when_max_transformation_depth_reached', 6);

        // KYC handler configuration
        $app['config']->set('kyc-handler.hyperverge.base_url', 'https://test.hyperverge.co/v1');
        $app['config']->set('kyc-handler.hyperverge.app_id', 'test_app_id');
        $app['config']->set('kyc-handler.hyperverge.app_key', 'test_app_key');
        $app['config']->set('kyc-handler.polling_interval', 5);
        $app['config']->set('kyc-handler.auto_redirect_delay', 2);
    }
}
