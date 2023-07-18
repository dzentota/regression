<?php
declare(strict_types=1);

namespace Regression;

use Regression\Adapter\SugarCRMAware;

/**
 * @deprecated This class is for backward compatibility. Use SugarCRMRegression instead
 */
abstract class SugarCRMScenario extends Regression
{
    use SugarCRMAware;
}