<?php
/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/Resources/Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */
declare(strict_types=1);

use Regression\SugarCRMScenario;

class RelateFieldDenormalizationRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return '[BR-8750] Broken access control. Relate Fields Denormalization is available for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $this
            ->login('max', 'max')
            ->apiCall("/Administration/denormalization/configuration")
            ->expectStatusCode(403)
            ->expectSubstring('not_authorized');
    }
}
