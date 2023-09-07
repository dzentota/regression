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
use Regression\Adapter\HeadlessBrowser;
use Regression\SugarCRMRegression;

class ExportEmployeesRegression extends SugarCRMRegression
{
    use HeadlessBrowser;
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return '[BR-8751] Broken access control. Export of Employees is available for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'POST',
            'index.php?entryPoint=export',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'uid=seed_will_id%2Cseed_sarah_id&module=Employees&action=index'
        );

        $this->login('jim', 'jim')
            ->bwcLogin()
            ->send($request)
            ->expectStatusCode(500)
            ->expectSubstring('No access', 'Response should contain an error message: "No access"');
    }
}
