<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\SugarCRMScenario;

class AdminRepairRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'IDOR - Admin functionalities (Repair) are accessible for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/index.php?module=Administration&action=Upgrade',
            //Send Referer to bypass XSRF check
            ['Referer' => $this->client->getConfig('base_uri') . '/index.php?module=Administration&action=GlobalSearchSettings&bwcFrame=1&bwcRedirect=1']
        );

        $this
            ->login('max', 'max')
            ->bwcLogin()
            ->send($request)
            ->expectStatusCode(500)
            ->expectSubstring('Unauthorized access to administration');
    }
}
