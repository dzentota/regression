<?php

namespace Regression\Helpers\Output;

use Symfony\Component\Console\Style\OutputStyle;

abstract class QueueOutputItem
{
    public ?QueueOutputItem $next = null;

    abstract public function execOutput(OutputStyle $style): callable;
}