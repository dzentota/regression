<?php
declare(strict_types=1);

namespace Regression\Attribute;

/**
 * Marks a scenario that changes instance-wide state (admin settings, Studio, packages, workflows...)
 * and therefore must not run concurrently with any other scenario.
 *
 *   #[Exclusive('installs an MLP')]
 *   class FooRegression extends SugarCRMRegression {}
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Exclusive
{
    public function __construct(public string $reason = '')
    {
    }
}
