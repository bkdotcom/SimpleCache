<?php

namespace bdk\SimpleCache\Tests\Scale;

use bdk\SimpleCache\Scale\StampedeProtector;

/**
 * The exact same as the real protector, but makes it possible to count the
 * amount of sleeps, so we can properly test if stampede protection actually
 * causes waiting the way we'd expect it.
 */
class StampedeProtectorStub extends StampedeProtector
{
    /**
     * Amount of times sleep() was called.
     *
     * @var integer
     */
    public $count = 0;

    /**
     * {@inheritdoc}
     */
    protected function sleep()
    {
        ++$this->count;
        return parent::sleep();
    }
}
