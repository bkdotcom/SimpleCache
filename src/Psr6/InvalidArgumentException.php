<?php

namespace bdk\SimpleCache\Psr6;

use bdk\SimpleCache\Exception\Exception;

class InvalidArgumentException extends Exception implements \Psr\Cache\InvalidArgumentException
{
}
