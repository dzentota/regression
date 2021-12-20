<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\SugarCRMScenario;

class ExposedXSRFTokenRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Misconfiguration. CSRF token exposed in GET parameters of Advanced Report';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $listRequest = new Request(
            'GET',
            '/index.php?module=ReportMaker&action=index&return_module=ReportMaker&return_action=index&bwcRedirect=1'
        );

        $scenario = $this->login('admin', 'asdf')
            ->bwcLogin()
            ->send($listRequest)
            ->extractRegexp('reportId', '~index\.php\?module=ReportMaker&offset=1&stamp=.*?&return_module=ReportMaker&action=EditView&record=(.*?)"~is');

        $detailRequest = new Request(
            'GET',
            '/index.php?module=ReportMaker&offset=1&return_module=ReportMaker&action=DetailView&record=' . $scenario->getVar('reportId'),
        );
        $scenario->send($detailRequest)
            ->expectStatusCode(200)
            ->expectSubstring('<form action="index.php" method="POST" name="DetailView" id="form"');
    }
}
