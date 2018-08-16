<?php

namespace bdk\SimpleCache\Tests;

use bdk\SimpleCache\KeyValueStoreInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use ReflectionClass;

class AdapterTestProvider
{
    /**
     * @var KeyValueStoreInterface[]
     */
    protected static $adapters = array();

    /**
     * @var TestCase
     */
    protected $testCase;

    /**
     * Constructor
     *
     * @param AdapterProviderTestInterface $testCase
     *
     * @throws Exception
     */
    public function __construct(AdapterProviderTestInterface $testCase)
    {
        if (!$testCase instanceof AdapterProviderTestInterface) {
            $class = get_class($testCase);
            throw new Exception(
                "AdapterTestProvider can't be used with a class ($class) that ".
                "doesn't implement AdapterProviderTestInterface."
            );
        }
        $this->testCase = $testCase;
    }

    /**
     * @return TestSuite
     */
    public function getSuite()
    {
        $suite = new TestSuite('Test integration');
        $i = 0;
        foreach ($this->getAdapterProviders() as $name => $adapterProvider) {
            fwrite(STDOUT, '## '.$name."\n");
            $class = new ReflectionClass(get_class($this->testCase));
            $tests = new TestSuite($class);

            // we can't use --filter to narrow down on specific adapters
            // (because we're not using dataProvider), but we can make sure it's
            // properly split up into groups & then use --group
            static::injectGroup($tests, $name);

            // and let's make sure to inject the specific adapter into the test
            static::injectAdapter($tests, $adapterProvider);

            // let's add all of the integrations tests for every adapter
            $suite->addTest($tests);

            ++$i;
        }

        return $suite;
    }

    /**
     * Injecting an adapter must be done recursively: there are some methods
     * that get input from dataProviders, so they're wrapped in another class
     * that we must unwrap in order to assign the adapter.
     *
     * @param TestSuite       $suite           Test Suite
     * @param AdapterProvider $adapterProvider Adapter Provider
     *
     * @return void
     */
    protected function injectAdapter(/* TestSuite|\PHPUnit_Framework_TestSuite */ $suite, AdapterProvider $adapterProvider)
    {
        foreach ($suite as $test) {
            /*
                Testing for both current (namespace) and old (underscored)
                PHPUnit class names, because (even though we stub this class)
                $test may be a child of TestSuite/PHPUnit_Framework_TestSuite,
                which the stub can't account for.
                The PHPUnit_Framework_TestSuite part can be removed when support
                for PHPUnit<6.0 is removed
            */
            if ($test instanceof TestSuite || $test instanceof \PHPUnit_Framework_TestSuite) {
                $this->injectAdapter($test, $adapterProvider);
            } else {
                /* @var AdapterTestCase $test */
                $test->setAdapter($adapterProvider->getAdapter());
                $test->setCollectionName($adapterProvider->getCollectionName());
            }
        }
    }

    /**
     * Because some tests are wrapped inside a dataProvider suite, we need to
     * make sure that the groups are recursively assigned to each suite until we
     * reach the child.
     *
     * @param TestSuite $suite test suite
     * @param string    $group dunno
     *
     * @return void
     */
    protected function injectGroup(/* TestSuite|\PHPUnit_Framework_TestSuite */ $suite, $group)
    {
        $tests = $suite->tests();
        $suite->setGroupDetails(array(
            'default' => $tests,
            $group => $tests,
        ));

        foreach ($suite->tests() as $test) {
            /*
                Testing for both current (namespace) and old (underscored)
                PHPUnit class names, because (even though we stub this class)
                $test may be a child of TestSuite/PHPUnit_Framework_TestSuite,
                which the stub can't account for.
                The PHPUnit_Framework_TestSuite part can be removed when support
                for PHPUnit<6.0 is removed
            */
            if ($test instanceof TestSuite || $test instanceof \PHPUnit_Framework_TestSuite) {
                $this->injectGroup($test, $group);
            }
        }
    }

    /**
     * @return AdapterProvider[]
     */
    public function getAdapterProviders()
    {
        // re-use adapters across tests - if we keep initializing clients, they
        // may fail because of too many connections (and it's just overhead...)
        if (static::$adapters) {
            return static::$adapters;
        }
        $adapters = $this->getAllAdapterProviders();
        foreach ($adapters as $class) {
            try {
                /* @var AdapterProvider $adapter */
                $fqcn = "\\bdk\\SimpleCache\\Tests\\Providers\\{$class}Provider";
                $adapter = new $fqcn();
                static::$adapters[$class] = $adapter;
            } catch (\Exception $e) {
                fwrite(STDOUT, "Exception: ".$class." ".$e->getMessage()."\n");
                static::$adapters[$class] = new AdapterProvider(new AdapterStub($e));
            }
        }
        return static::$adapters;
    }

    /**
     * @return string[]
     */
    protected function getAllAdapterProviders()
    {
        $files = glob(__DIR__.'/Providers/*Provider.php');
        $adapters = array_map(function ($file) {
            return basename($file, 'Provider.php');
        }, $files);
        return $adapters;
    }
}
