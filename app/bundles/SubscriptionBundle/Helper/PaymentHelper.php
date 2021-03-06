<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\SubscriptionBundle\Helper;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\SubscriptionBundle\Entity\Account;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

/**
 * Class PaymentHelper.
 */
class PaymentHelper
{
    /**
     * @var MauticFactory
     */
    protected $factory;

    public function __construct(MauticFactory $factory)
    {
        $this->factory   = $factory;
    }

    public function getPayPalApiContext()
    {
        $clientid        =$this->factory->getParameter('paypal_clientid');
        $clientsecret    =$this->factory->getParameter('paypal_clientsecret');
        $paypalmode      =$this->factory->getParameter('paypal_mode');
        $paypallogpath   =$this->factory->getParameter('paypal_logpath');
        $paypalloglevel  =$this->factory->getParameter('paypal_loglevel');
        $paypallogenabled=$this->factory->getParameter('paypal_log_enabled');
        $paypalcachepath =$this->factory->getParameter('paypal_cachepath');
        $paypalrootpath  =$this->factory->getParameter('paypal_rootpath');
        if (!is_dir($paypalrootpath) && !file_exists($paypalrootpath)) {
            mkdir($paypalrootpath, 0777);
        }
        if (!is_dir($paypallogpath) && !file_exists($paypallogpath)) {
            mkdir($paypallogpath, 0777);
        }
        if (!is_dir($paypalcachepath) && !file_exists($paypalcachepath)) {
            mkdir($paypalcachepath, 0777);
        }
        $dataArray['provider'] ='paypal';
        $apiContext            = new ApiContext(
            new OAuthTokenCredential(
                $clientid,
                $clientsecret
            )
        );
        $apiContext->setConfig(
            [
                'mode'           => $paypalmode,
                'log.LogEnabled' => $paypallogenabled,
                'log.FileName'   => $paypallogpath.'/paypal.log',
                'log.LogLevel'   => $paypalloglevel, // PLEASE USE `INFO` LEVEL FOR LOGGING IN LIVE ENVIRONMENTS
                'cache.enabled'  => true,
                'cache.FileName' => $paypalcachepath.'/auth.cache', // for determining paypal cache directory
                // 'http.CURLOPT_CONNECTTIMEOUT' => 30
                // 'http.headers.PayPal-Partner-Attribution-Id' => '123123123'
                //'log.AdapterFactory' => '\PayPal\Log\DefaultLogFactory' // Factory class implementing \PayPal\Log\PayPalLogFactory
            ]
        );

        return $apiContext;
    }

    public function getUUIDv4()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),

            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,

            // 48 bits for "node"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * @param User $user
     */
    public function sendPaymentNotification($paymenthistory, $billing, $mailer)
    {
        $mailer->start();
        $invoicelink  = $this->factory->getRouter()->generate('le_viewinvoice_action', ['id' => $paymenthistory->getId()], true);
        $message      = \Swift_Message::newInstance();
        $message->setTo([$billing->getAccountingemail() => $billing->getCompanyname()]);
        $message->setFrom(['notifications@anyfunnels.io' => 'AnyFunnels']);
        $message->setReplyTo(['support@anyfunnels.com' => 'AnyFunnels']);
        $message->setBcc(['sales@anyfunnels.com' => 'AnyFunnels']);
        $subject     = $this->factory->getTranslator()->trans($paymenthistory->getTaxamount() ? 'le.payment.addon.received.alert' : 'le.payment.received.alert');
        $message->setSubject($subject);
        $datehelper =$this->factory->getDateHelper();
        $processedat=$datehelper->toDate($paymenthistory->getcreatedOn());
        $user       = $this->factory->getUser();
        $firstname  = $billing->getCompanyname();
        if ($user != null) {
            $firstname = $user->getFirstName();
        }
        $amount      = '$'.$paymenthistory->getNetamount();
        $bodycontent = "Hey, $firstname!<br><br>We received your payment $amount on $processedat, for AnyFunnels monthly subscription.<br><br>This payment information has been updated in your account, and you can download the invoice any time from payments history tab in account settings.<br><br>Thanks for your business!<br>AnyFunnels Team.";

        if ($paymenthistory->getTaxamount()) {
            $bodycontent = "Hey, <br><br>We received your payment $amount on $processedat, for additional emails credits.<br><br>This payment information has been updated in your account, and you can download the invoice any time from payments history tab in AnyFunnels account settings.<br><br>Thanks for your business!<br>AnyFunnels Team.";
        }
        $text = "<html>
            <body>
                <div>
                    <span style=\"font-size: 12px;\">
                    <span style=\"font-family: Verdana,Geneva,sans-serif;\">$bodycontent</span></span>
                </div>
            </body>
        </html>";
        //$html = nl2br($text);

        $message->setBody($text, 'text/html');
        //$mailer->setPlainText(strip_tags($text));

        $mailer->send($message);
    }

    public function paymentFailedEmailtoUser($mailer, $planname)
    {
        $mailer->start();
        /** @var \Mautic\SubscriptionBundle\Model\AccountInfoModel $model */
        $model         = $this->factory->getModel('subscription.accountinfo');
        $accrepo       = $model->getRepository();
        $accountentity = $accrepo->findAll();
        if (sizeof($accountentity) > 0) {
            $account = $accountentity[0];
        } else {
            $account = new Account();
        }
        $name       = $account->getAccountname();
        $useremail  =$account->getEmail();
        $domain     =$account->getDomainname();
        $message    = \Swift_Message::newInstance();
        $message->setTo([$useremail => $name]);
        $message->setFrom(['notifications@anyfunnels.io' => 'AnyFunnels']);
        $message->setReplyTo(['support@anyfunnels.com' => 'AnyFunnels']);
        $message->setSubject('[IMPORTANT] Your credit card was declined');
        $text = "<!DOCTYPE html>
        <html>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>

	<head>
		<title></title>
	</head>
	<body>
		<div>
        Hey, $name!
        <br>
       
        <br>We tried to process a charge of $49 for your AnyFunnels monthly subscription ($planname) for your account ($domain.anyfunnels.com), but your credit card was declined.

        <br>Kindly take necessary action to continue AnyFunnels subscription.
        </div>
		
	</body>
</html>";
        $message->setBody($text, 'text/html');
        $mailer->send($message);
    }
}
