<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\Severity;
use Regression\SugarCRMAssessment;


/**
 * Expected to be run with a headless Chrome
 */
class Demo2Assessment extends SugarCRMAssessment
{
    use \Regression\Adapter\HeadlessBrowserAssessment;
    
    public function getSeverity(): ?string
    {
        return Severity::INFO;
    }

    /**
     * @return string
     */
    public function getAssessmentDescription(): string
    {
        return 'SugarCRM version disclosure';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/xss.html' // this page should pop up a JS alert
        );

        $this->send($request)
            ->assumeNoXss($xss)
            ->checkAssumptions('Found an XSS vulnerability', $xss);
    }
}
