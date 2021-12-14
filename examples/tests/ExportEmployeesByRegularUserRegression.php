<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\SugarCRMScenario;

class ExportEmployeesByRegularUserRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Export of Employees is available for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'POST',
            '/index.php?entryPoint=export',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'uid=seed_will_id%2Cseed_sarah_id&module=Employees&action=index'
        );

        $this->login('jim', 'jim')
            ->send($request)
            ->expectSubstring('No access');
    }
}
