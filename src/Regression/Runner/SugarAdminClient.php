<?php
declare(strict_types=1);

namespace Regression\Runner;

use Regression\SugarCRMRegression;

/**
 * Admin session for runner-level operations (API platforms, license) outside of any test.
 */
final class SugarAdminClient extends SugarCRMRegression
{
    public function getRegressionDescription(): string
    {
        return 'Runner admin client';
    }

    public function run(): void
    {
    }

    public static function workerPlatform(int $worker): string
    {
        return 'regression_w' . $worker;
    }
}
