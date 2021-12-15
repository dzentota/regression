<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Regression\SugarCRMScenario;

class RelateFieldDenormalizationRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return 'Broken access control. Relate Fields Denormalization is available for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'GET',
            '/rest/v11_15/Administration/denormalization/configuration',
        );

        $this
            ->login('max', 'max')
            ->send($request)
            ->expectStatusCode(403)
            ->expectSubstring('not_authorized');
    }
}
