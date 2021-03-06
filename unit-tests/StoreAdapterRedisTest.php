<?php

defined('UNIT_TESTS_ROOT') || require __DIR__ . '/bootstrap.php';

class StoreAdapterRedisTest extends TestCase
{
    public $di;

    public function setUp()
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        $this->di = new \ManaPHP\Di\FactoryDefault();
        $this->di->setShared('redis', function () {
            $redis = new \Redis();
            $redis->connect('localhost');
            return $redis;
        });
    }

    public function test_exists()
    {
        $store = new \ManaPHP\Store\Adapter\Redis();
        $store->setDependencyInjector($this->di);

        $store->delete('var');

        $this->assertFalse($store->exists('var'));
        $store->set('var', 'value');
        $this->assertTrue($store->exists('var'));
    }

    public function test_get()
    {
        $store = new \ManaPHP\Store\Adapter\Redis();
        $store->setDependencyInjector($this->di);

        $store->delete('var');

        $this->assertFalse($store->get('var'));
        $store->set('var', 'value');
        $this->assertSame('value', $store->get('var'));
    }

    public function test_set()
    {
        $store = new \ManaPHP\Store\Adapter\Redis();
        $store->setDependencyInjector($this->di);

        $store->set('var', '');
        $this->assertSame('', $store->get('var'));

        $store->set('var', 'value');
        $this->assertSame('value', $store->get('var'));

        $store->set('var', '{}');
        $this->assertSame('{}', $store->get('var'));
    }

    public function test_delete()
    {
        $store = new \ManaPHP\Store\Adapter\Redis();
        $store->setDependencyInjector($this->di);

        //exists and delete
        $store->set('var', 'value');
        $store->delete('var');

        // missing and delete
        $store->delete('var');
    }
}