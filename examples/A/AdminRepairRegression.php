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

use GuzzleHttp\Psr7\Request;

class AdminRepairRegression extends \Regression\SugarCRMRegression
{
    use \Regression\Adapter\HeadlessBrowser;
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return '[BR-8204] IDOR - Admin functionalities (Repair) are accessible for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            'index.php?module=Administration&action=Upgrade',
            //Send Referer to bypass XSRF check
            ['Referer' => $this->getBaseUri() . '/index.php?module=Administration&action=GlobalSearchSettings&bwcFrame=1&bwcRedirect=1']
        );

        $this
            ->login('max', 'max')
            ->bwcLogin()
            ->send($request)
            ->expectStatusCode(500)
            ->expectSubstring('Unauthorized access to administration');
    }
}
