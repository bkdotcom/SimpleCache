<?php

namespace bdk\SimpleCache\Psr16;

use bdk\SimpleCache\Exception\Exception;

class InvalidArgumentException extends Exception implements \Psr\SimpleCache\InvalidArgumentException
{
}
