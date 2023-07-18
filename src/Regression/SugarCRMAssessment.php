<?php
declare(strict_types=1);

namespace Regression;

use Regression\Adapter\SugarCRMAware;

abstract class SugarCRMAssessment extends Assessment
{
    use SugarCRMAware;
}