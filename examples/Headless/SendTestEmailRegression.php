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
use Regression\SugarCRMScenario;

class SendTestEmailRegression extends SugarCRMScenario
{
    /**
     * @return string
     */
    public function getRegressionDescription(): string
    {
        return '[BR-8749] Broken access control. Send Test Email is available for a regular user';
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Regression\RegressionException
     */
    public function run(): void
    {
        $request = new Request(
            'POST',
            'index.php?action=testOutboundEmail&module=EmailMan&to_pdf=true&sugar_body_only=true',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'mail_type=system&mail_sendtype=SMTP&mail_smtpserver=mailhog&mail_smtpport=1025&mail_smtpssl=0&mail_smtpauth_req=false&mail_smtpuser=&mail_smtppass=&outboundtest_to_address=webtota%40gmail.com&outboundtest_from_address=do_not_reply@example.com&mail_from_name=SugarCRM&mail_smtptype=other&mail_authtype=&eapm_id=&authorized_account='
        );

        $this->login('jim', 'jim')
            ->bwcLogin()
            ->send($request)
            ->expectStatusCode(500)
            ->expectSubstring('Unauthorized access to administration.');
    }
}
