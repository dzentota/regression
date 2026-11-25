<?php
declare(strict_types=1);

namespace Regression\Attribute;

/**
 * Declares shared resources a scenario changes. Scenarios using the same resource never run at the
 * same time; others still run in parallel with them.
 *
 *   #[Uses('packages')]        // uploads packages: list of uploaded packages
 *   #[Uses('user:sarah')]      // changes sarah's roles/preferences
 *
 * Read access to users (every loginAs('x')) is detected automatically.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Uses
{
    /** @var string[] */
    public array $resources;

    public function __construct(string ...$resources)
    {
        $this->resources = $resources;
    }
}
