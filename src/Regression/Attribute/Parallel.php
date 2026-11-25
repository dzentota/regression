<?php
declare(strict_types=1);

namespace Regression\Attribute;

/**
 * Explicitly allows a scenario to run in parallel, overriding auto-detection of exclusive scenarios.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Parallel
{
}
