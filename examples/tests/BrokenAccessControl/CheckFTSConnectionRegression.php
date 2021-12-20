<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\SugarCRMScenario;

class CheckFTSConnectionRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Broken access control. Check FTS connection is available for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/index.php?to_pdf=1&module=Administration&action=checkFTSConnection&type=Elastic&host=local&port=9200',
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
