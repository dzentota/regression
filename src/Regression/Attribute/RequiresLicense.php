<?php
declare(strict_types=1);

namespace Regression\Attribute;

/**
 * Runs the scenario only if the instance has at least one of the given license types
 * (as reported by GET /me -> current_user.licenses), otherwise it is skipped.
 *
 *   #[RequiresLicense('SUGAR_SERVE')]                  // Serve only
 *   #[RequiresLicense('SUGAR_SELL', 'SUGAR_SELL_ADVANCED_BUNDLE', 'SUGAR_SELL_PREMIER_BUNDLE')]
 *   #[RequiresLicense('CURRENT')]                      // classic Ent/Pro editions
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RequiresLicense
{
    /** @var string[] */
    public array $licenses;

    public function __construct(string ...$licenses)
    {
        $this->licenses = $licenses;
    }
}
