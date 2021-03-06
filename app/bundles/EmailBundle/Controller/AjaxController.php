<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Controller\AjaxLookupControllerTrait;
use Mautic\CoreBundle\Controller\VariantAjaxControllerTrait;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\EmailBundle\Entity\AwsVerifiedEmails;
use Mautic\EmailBundle\Entity\LeadEventLogRepository;
use Mautic\EmailBundle\Entity\SendingDomain;
use Mautic\EmailBundle\Helper\PlainTextHelper;
use Mautic\EmailBundle\Model\DripEmailModel;
use Mautic\EmailBundle\Model\EmailModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class AjaxController.
 */
class AjaxController extends CommonAjaxController
{
    use VariantAjaxControllerTrait;
    use AjaxLookupControllerTrait;

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function getAbTestFormAction(Request $request)
    {
        return $this->getAbTestForm(
            $request,
            'email',
            'email_abtest_settings',
            'emailform',
            'MauticEmailBundle:AbTest:form.html.php',
            ['MauticEmailBundle:AbTest:form.html.php', 'MauticEmailBundle:FormTheme\Email']
        );
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function sendBatchAction(Request $request)
    {
        $dataArray = ['success' => 0];

        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model    = $this->getModel('email');
        $objectId = $request->request->get('id', 0);
        $pending  = $request->request->get('pending', 0);
        $limit    = $request->request->get('batchlimit', 100);

        if ($objectId && $entity = $model->getEntity($objectId)) {
            $dataArray['success'] = 1;
            $session              = $this->container->get('session');
            $progress             = $session->get('mautic.email.send.progress', [0, (int) $pending]);
            $stats                = $session->get('le.email.send.stats', ['sent' => 0, 'failed' => 0, 'failedRecipients' => []]);
            $inProgress           = $session->get('mautic.email.send.active', false);

            if ($pending && !$inProgress && $entity->isPublished()) {
                $session->set('mautic.email.send.active', true);
                list($batchSentCount, $batchFailedCount, $batchFailedRecipients) = $model->sendEmailToLists($entity, null, $limit);

                $progress[0] += ($batchSentCount + $batchFailedCount);
                $stats['sent'] += $batchSentCount;
                $stats['failed'] += $batchFailedCount;

                foreach ($batchFailedRecipients as $list => $emails) {
                    $stats['failedRecipients'] = $stats['failedRecipients'] + $emails;
                }

                $session->set('mautic.email.send.progress', $progress);
                $session->set('le.email.send.stats', $stats);
                $session->set('mautic.email.send.active', false);
            }

            $dataArray['percent']  = ($progress[1]) ? ceil(($progress[0] / $progress[1]) * 100) : 100;
            $dataArray['progress'] = $progress;
            $dataArray['stats']    = $stats;
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * Called by parent::getBuilderTokensAction().
     *
     * @param $query
     *
     * @return array
     */
    protected function getBuilderTokens($query)
    {
        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');

        return $model->getBuilderComponents(null, ['tokens'], $query, false);
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function generatePlaintTextAction(Request $request)
    {
        $custom = $request->request->get('custom');
        $id     = $request->request->get('id');

        $parser = new PlainTextHelper(
            [
                'base_url' => $request->getSchemeAndHttpHost().$request->getBasePath(),
            ]
        );

        $dataArray = [
            'text' => $parser->setHtml($custom)->getText(),
        ];

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function getAttachmentsSizeAction(Request $request)
    {
        $assets = $request->get('assets', [], true);
        $size   = 0;
        if ($assets) {
            /** @var \Mautic\AssetBundle\Model\AssetModel $assetModel */
            $assetModel = $this->getModel('asset');
            $size       = $assetModel->getTotalFilesize($assets);
        }

        return $this->sendJsonResponse(['size' => $size]);
    }

    /**
     * Tests monitored email connection settings.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    protected function testMonitoredEmailServerConnectionAction(Request $request)
    {
        $dataArray = ['success' => 0, 'message' => ''];

        if ($this->user->isAdmin()) {
            $settings = $request->request->all();

            if (empty($settings['password'])) {
                $existingMonitoredSettings = $this->coreParametersHelper->getParameter('monitored_email');
                if (is_array($existingMonitoredSettings) && (!empty($existingMonitoredSettings[$settings['mailbox']]['password']))) {
                    $settings['password'] = $existingMonitoredSettings[$settings['mailbox']]['password'];
                }
            }

            /** @var \Mautic\EmailBundle\MonitoredEmail\Mailbox $helper */
            $helper = $this->factory->getHelper('mailbox');

            try {
                $helper->setMailboxSettings($settings, false);
                $folders = $helper->getListingFolders('');
                if (!empty($folders)) {
                    $dataArray['folders'] = '';
                    foreach ($folders as $folder) {
                        $dataArray['folders'] .= "<option value=\"$folder\">$folder</option>\n";
                    }
                }
                $dataArray['success'] = 1;
                $dataArray['message'] = $this->translator->trans('mautic.core.success');
            } catch (\Exception $e) {
                $dataArray['message'] = $e->getMessage();
            }
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * Tests mail transport settings.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    protected function testEmailServerConnectionAction(Request $request)
    {
        $dataArray = ['success' => 0, 'message' => '', 'to_address_empty'=>false];
        $user      = $this->get('mautic.helper.user')->getUser();

        if ($user->isAdmin() || $user->isCustomAdmin()) {
            $settings   = $request->request->all();
            $mailHelper = $this->get('mautic.helper.mailer');
            $dataArray  = $mailHelper->testEmailServerConnection($settings, true);
        }

        return $this->sendJsonResponse($dataArray);
    }

    /**
     * @param Request $request
     */
    protected function getEmailCountStatsAction(Request $request)
    {
        /** @var EmailModel $model */
        $model       = $this->getModel('email');
        $cacheHelper = $this->factory->getHelper('cache_storage');

        $id  = $request->get('id');
        $ids = $request->get('ids');
        // Support for legacy calls
        if (!$ids && $id) {
            $ids = [$id];
        }

        $data = [];
        foreach ($ids as $id) {
            if ($email = $model->getEntity($id)) {
                $pending     = $model->getPendingLeads($email, null, true);
                $queued      = $model->getQueuedCounts($email);
                $sentCount   = $email->getSentCount(true);
                $failureCount= $email->getFailureCount(true);
                $unsubCount  = $email->getUnsubscribeCount(true);
                $bounceCount = $email->getBounceCount(true);
                $spamCount   = $email->getSpamCount(true);
                $failedCount = $model->getEmailFailedCount($email->getId());
                $totalCount  = $pending + $sentCount;

                $clickCount = $model->getEmailClickCount($email->getId());
                if ($sentCount > 0 && $totalCount > 0) {
                    $totalSentPec = round($sentCount / $totalCount * 100);
                } else {
                    $totalSentPec = 0;
                }
                if ($failureCount > 0 && $totalCount > 0) {
                    $failurePercentage = round($failureCount / $totalCount * 100, 2);
                } else {
                    $failurePercentage = 0;
                }
                if ($unsubCount > 0 && $totalCount > 0) {
                    $unSubPercentage = round($unsubCount / $sentCount * 100, 2);
                } else {
                    $unSubPercentage = 0;
                }
                if ($bounceCount > 0 && $sentCount > 0) {
                    $bouncePercentage = round($bounceCount / $sentCount * 100, 2);
                } else {
                    $bouncePercentage = 0;
                }
                if ($spamCount > 0 && $sentCount > 0) {
                    $spamPercentage = round($spamCount / $sentCount * 100, 2);
                } else {
                    $spamPercentage = 0;
                }
                if ($clickCount > 0 && $sentCount > 0) {
                    $clickCountPercentage = round($clickCount / $sentCount * 100);
                } else {
                    $clickCountPercentage = 0;
                    $clickCount           =0;
                }
                $failedPercentage = 0;
                if ($failedCount > 0 && $totalCount > 0) {
                    $failedPercentage = round($failedCount / $totalCount * 100);
                }

                $cacheHelper->set(sprintf('%s|%s|%s', 'email', $email->getId(), 'click_percentage'), $clickCountPercentage);
                $cacheHelper->set(sprintf('%s|%s|%s', 'email', $email->getId(), 'unsubscribe_percentage'), $unSubPercentage);

                $data[] = [
                    'id'          => $id,
                    'pending'     => 'list' === $email->getEmailType() && $pending ? $this->translator->trans(
                        'le.email.stat.leadcount',
                        ['%count%' => $pending]
                    ) : 0,
                    'queued'           => ($queued) ? $this->translator->trans('le.email.stat.queued', ['%count%' => $queued]) : 0,
                    'sentCount'        => $this->translator->trans('le.email.stat.sentcount', ['%count%' =>$sentCount, '%percentage%'=>$totalSentPec]),
                    'readCount'        => $this->translator->trans('le.email.stat.readcount', ['%count%' => $email->getReadCount(true), '%percentage%' => round($email->getReadPercentage(true))]),
                    'readPercent'      => $this->translator->trans('le.email.stat.readpercent', ['%count%' => $clickCount, '%percentage%'=>$clickCountPercentage]),
                    'failureCount'     => $this->translator->trans('le.email.stat.failurecount', ['%count%' => $failureCount, '%percentage%'=>$failurePercentage]),
                    'unsubscribeCount' => $this->translator->trans('le.email.stat.unsubscribecount', ['%count%' =>$unsubCount, '%percentage%'=>$unSubPercentage]),
                    'bounceCount'      => $this->translator->trans('le.email.stat.bouncecount', ['%count%' => $bounceCount, '%percentage%' => $bouncePercentage]),
                    'spamCount'        => $this->translator->trans('le.email.stat.spamcount', ['%count%' => $spamCount, '%percentage%' => $spamPercentage]),
                    'failedCount'      => $this->translator->trans('le.email.stat.failedcount', ['%count%' => $failedCount, '%percentage%' => $failedPercentage]),
                ];
            }
        }

        // Support for legacy calls
        if ($request->get('id')) {
            $data = $data[0];
        } else {
            $data = [
                'success' => 1,
                'stats'   => $data,
            ];
        }

        return new JsonResponse($data);
    }

    public function senderProfileVerifyAction(Request $request)
    {
        $fromemail     = InputHelper::clean($request->request->get('email'));
        $fromname      = InputHelper::clean($request->request->get('name'));
        $dataArray     =[];
        $validator     = $this->container->get('validator');
        $constraints   = [
            new \Symfony\Component\Validator\Constraints\Email(),
            new \Symfony\Component\Validator\Constraints\NotBlank(),
        ];
        $error = $validator->validateValue($fromemail, $constraints);

        if (count($error) > 0) {
            $errors[]            = $error;
            $dataArray['success']=false;
            $dataArray['message']=$this->translator->trans('le.core.email.required');
        } else {
            $dataArray=$this->verifyNewSenderProfile($fromemail, $fromname);
        }

        return $this->sendJsonResponse($dataArray);
    }

    private function verifyNewSenderProfile($fromemail, $fromname, $action='created')
    {
//        $this->verifySparkPostSender($fromemail,$fromname);
//        if(true){
//         return  ['success'=>false,'message'=> 'test'];
//        }
        $dataArray=['success'=>true, 'message'=> '', 'response'=>''];
        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel       = $this->factory->getModel('email');
        $verifiedemailRepo=$emailModel->getAwsVerifiedEmailsRepository();
        $status           =$emailModel->isSenderProfileVerified($fromemail);
        if ($status == '0' && $action == 'created') {
            $dataArray['success'] = false;
            $dataArray['message'] = $this->translator->trans('le.aws.email.verification.verified');
        } elseif ($status == '1' && $action == 'created') {
            $dataArray['success'] = false;
            $dataArray['message'] = $this->translator->trans('le.aws.email.verification.pending');
        } elseif ($status == '2' || $action == 'updated') {
            $response=$this->verifySenderWithMailer($fromname, $fromemail, $action);
            if ($response['success']) {
                $returnUrl             = $this->generateUrl('le_config_action', ['objectAction' => 'edit']);
                $dataArray['success']  = true;
                $dataArray['message']  = $this->translator->trans('le.aws.email.verification');
                $dataArray['redirect'] = $returnUrl;
                if ($action == 'created') {
                    $idHash = uniqid();
                    $entity = new AwsVerifiedEmails();
                    $entity->setVerifiedEmails($fromemail);
                    $entity->setFromName($fromname);
                    $entity->setVerificationStatus('1');
                    $entity->setIdHash($idHash);
                    $entity->setInboxverified(0);
                    $verifiedemailRepo->saveEntity($entity);
                    $mailresponse          =$this->sendSenderVerificationEmail($fromemail, $fromname, $idHash);
                    $dataArray['response'] = $mailresponse;
                /*if ($mailresponse == '') {
                    $this->addFlash('le.email.sender.profile.verification.sent.notification', ['%sender%'=>$fromemail]);
                } else {
                    $this->addFlash('le.config.sender.email.verification.error');
                }*/
                } else {
                    $senderprofiles=$verifiedemailRepo->findBy(
                        [
                            'verifiedemails' => $fromemail,
                        ]
                    );
                    if (sizeof($senderprofiles) > 0) {
                        $senderprofile      =$senderprofiles[0];
                        $verificationStatus = '0';
                        if (!$senderprofile->getInboxverified()) {
                            $idhash                = $senderprofile->getIdHash();
                            $mailresponse          =$this->sendSenderVerificationEmail($fromemail, $fromname, $idhash);
                            if ($mailresponse == '') {
                                $dataArray['response'] = $this->translator->trans('le.email.senderverification.inbox.failed', ['%EMAIL%' => $fromemail]);
                            } else {
                                $dataArray['response'] = $mailresponse;
                            }
                            /* if ($mailresponse == '') {
                                 $this->addFlash('le.email.sender.profile.verification.sent.notification', ['%sender%'=>$fromemail]);
                             } else {
                                 $this->addFlash('le.config.sender.email.verification.error');
                             }*/
                            $verificationStatus = '1';
                        }
                        $senderprofile->setVerificationStatus($verificationStatus);
                        $verifiedemailRepo->saveEntity($senderprofile);
                    }
                }
            } else {
                return $response;
            }
        }

        return $dataArray;
    }

    public function verifySenderWithMailer($fromname, $fromemail, $action)
    {
        $dataArray  =['success'=>true, 'message'=>''];
        if ($action == 'updated') {
            return $dataArray;
        }
        $mailHelper = $this->get('mautic.helper.mailer');
        /** @var \Mautic\CoreBundle\Configurator\Configurator $configurator */
        $configurator     = $this->factory->get('mautic.configurator');
        $params           = $configurator->getParameters();
        $transport        = $params['mailer_transport'];
        $transportlabel   =$this->translator->trans($transport);
        $mailer           = $this->container->get($transport);
        $user             = $this->factory->get('mautic.helper.user')->getUser();
        if ($action == 'created') {
            $msg = 'added';
            $sub ='Added a new';
        } else {
            $msg = 'verified';
            $sub ='Verified the';
        }
        try {
            $userFullName = trim($user->getFirstName().' '.$user->getLastName());
            if (empty($userFullName)) {
                $userFullName = null;
            }
            $mailer->start();
            $message = \Swift_Message::newInstance()
                ->setSubject($this->translator->trans('le.email.config.mailer.transport.sender.verify.subject', ['%action%'=> $sub]));
            $mailbody = $this->translator->trans('le.email.config.mailer.transport.sender.verify.body', ['%action%'=> $msg, '%SenderEmail%'=> $fromemail, '%name%'=> $user->getFirstName(), '%SenderName%' => $fromname]);
            $message->setBody($mailbody, 'text/html');
            $message->setFrom([$fromemail => $fromname]);
            $message->setTo([$user->getEmail() => $userFullName]);
            $mailer->send($message);
        } catch (\Exception $ex) {
            $dataArray['success'] = false;
            $dataArray['message'] = $mailHelper->geterrormsg($ex->getMessage()).'<b>'.' ('.$transportlabel.')'.'</b>';
        }

        return $dataArray;
    }

    public function sendSenderVerificationEmail($fromemail, $fromname, $idHash)
    {
        $mailer = $this->container->get('le.transport.elasticemail.transactions');
        $mailer->start();
        $verifylink        = $this->generateUrl('le_sender_profile_verify_link', ['idhash' => $idHash], true);
        $message           = \Swift_Message::newInstance();
        $name              = $this->factory->getUser()->getFirstName();
        $message->setTo([$fromemail => $fromname]);
        $message->setReplyTo($this->factory->getUser()->getEmail());
        $message->setFrom(['notifications@anyfunnels.io' => 'AnyFunnels']);
        $message->setSubject($this->translator->trans('le.sender.verification.subject'));
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
<br>We have received a request to authorize this email address for use with AnyFunnels Marketing Automation Platform.
<br>
<br>If you requested this verification, please <a href='$verifylink'>click here</a> to confirm that you are authorized to use this email address.
</div>
	</body>
</html>";
        $message->setBody($text, 'text/html');
        $mailresponse='';
        try {
            $mailer->send($message);
        } catch (\Exception $ex) {
            $mailresponse=  $ex->getMessage();
        }

        return $mailresponse;
    }

    private function verifySparkPostSender($fromemail, $fromname)
    {
        $dataArray =['success'=>true, 'message'=> ''];
        $http      = $this->get('mautic.http.connector');
        $domainname= substr(strrchr($fromemail, '@'), 1);
        try {
            $response = $http->get('https://api.sparkpost.com/api/v1/sending-domains?ownership_verified=true', ['Authorization'=>'75df76268ba43535a0d5f0b27ff07e9c19053815'], 30);
            if ($response->code === 200) {
                $successresponse = json_decode($response->body, true);
            // file_put_contents('/var/www/mauto/sparkpost.txt', 'Data:'.$response->body."\n", FILE_APPEND);
            } else {
                $errresponse =json_decode($response->body, true);
                $errormessage='';
                if (isset($errresponse['errors'][0]['message'])) {
                    $errormessage=$errresponse['errors'][0]['message'];
                    if ($errormessage == 'Unauthorized.') {
                        $errormessage='Provide valid sparkpost api key to check verified sending domains.';
                    } elseif ($errormessage == 'Forbidden.') {
                        $errormessage='Provide permission to your api key yo check sending domains in sparkpost account';
                    }
                    $dataArray['success']=false;
                    $dataArray['message']=$errormessage;
                }
            }
        } catch (\Exception $ex) {
            $dataArray['success']=false;
            $dataArray['message']=$ex->getMessage();
        }

        return $dataArray;
    }

    private function verifyAWSSender($fromemail, $fromname)
    {
        $dataArray=['success'=>true, 'message'=> ''];
        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel       = $this->factory->getModel('email');
        $emailValidator   = $this->factory->get('mautic.validator.email');
        $getAllEmailIds   = $emailModel->getAllEmailAddress();
        $awsVeridiedIds   =$emailModel->getVerifiedEmailAddress();
        $verifiedemailRepo=$emailModel->getAwsVerifiedEmailsRepository();

        /** @var \Mautic\CoreBundle\Configurator\Configurator $configurator */
        $configurator     = $this->factory->get('mautic.configurator');
        $params           = $configurator->getParameters();
        $emailuser        = $params['mailer_user'];
        if (isset($params['mailer_amazon_region'])) {
            $region                = $params['mailer_amazon_region'];
        } else {
            $region='';
        }
        //$region           = $params['mailer_amazon_region'];
        $emailpassword    = $params['mailer_password'];
        $emailverifyhelper= $this->factory->get('mautic.validator.email');

        $awsAccountStatus = $emailValidator->getAwsAccountStatus($emailuser, $emailpassword, $region);
        $verifiedEmails   = $emailValidator->getVerifiedEmailList($emailuser, $emailpassword, $region);
        $isValidEmail     = $emailValidator->getVerifiedEmailAddressDetails($emailuser, $emailpassword, $region, $fromemail);
        $returnUrl        = $this->generateUrl('le_config_action', ['objectAction' => 'edit']);
        /** @var \Symfony\Bundle\FrameworkBundle\Templating\Helper\RouterHelper $routerHelper */
        $awscallbackurl = $this->get('templating.helper.router')->url('le_mailer_transport_callback', ['transport' => 'amazon_api']);
        if ($isValidEmail == 'Policy not written') {
            $dataArray['success'] = false;
            $dataArray['message'] = $this->translator->trans('le.email.verification.policy.error');
        }
        if (!$awsAccountStatus && sizeof($verifiedEmails) > 0) {
            $awsAccountStatus=true;
        }
        $verifystatus='Pending';
        if ($isValidEmail) {
            $verifystatus          ='Verified';
            $dataArray['success']  = true;
            $dataArray['redirect'] = $returnUrl;
        }
        if (!in_array($fromemail, $getAllEmailIds)) {
            $entity = new AwsVerifiedEmails();
            $entity->setVerifiedEmails($fromemail);
            $entity->setFromName($fromname);
            $entity->setVerificationStatus($verifystatus);
            $verifiedemailRepo->saveEntity($entity);
            if (!$isValidEmail && $awsAccountStatus) {
                $result = $emailverifyhelper->sendVerificationMail($emailuser, $emailpassword, $region, $fromemail, $awscallbackurl);
                if ($result == 'Policy not written') {
                    $dataArray['success'] = false;
                    $dataArray['message'] = $this->translator->trans('le.email.verification.policy.error');
                } elseif ($result == 'Sns Policy not written') {
                    $dataArray['success'] = false;
                    $dataArray['message'] = $this->translator->trans('le.email.verification.sns.policy.error');
                } else {
                    $this->addFlash('le.config.aws.email.verification');
                    $dataArray['success']  = true;
                    $dataArray['message']  = $this->translator->trans('le.aws.email.verification');
                    $dataArray['redirect'] = $returnUrl;
                }
            } else {
                if (!$awsAccountStatus) {
                    $dataArray['success']  = false;
                    $dataArray['message']  = $this->translator->trans('le.email.verification.inactive.key');
                } else {
                    $dataArray['success']  = true;
                    $dataArray['message']  = $this->translator->trans('le.aws.email.verification');
                    $dataArray['redirect'] = $returnUrl;
                }
            }
        } else {
            if (!in_array($fromemail, $awsVeridiedIds)) {
                $dataArray['success'] = false;
                $dataArray['message'] = $this->translator->trans('le.aws.email.verification.pending');
            } else {
                $dataArray['success'] = false;
                $dataArray['message'] = $this->translator->trans('le.aws.email.verification.verified');
            }
        }

        return $dataArray;
    }

    public function deleteSenderProfileAction(Request $request)
    {
        $emailModel = $this->factory->getModel('email');
        $email      = $request->request->get('email');
        $response   = $emailModel->deleteAwsVerifiedEmails($email);

        if ($response == 'success') {
            $returnUrl             = $this->generateUrl('le_config_action', ['objectAction' => 'edit']);
            $dataArray['success']  =true;
            $dataArray['redirect'] =$returnUrl;
        } elseif ($response == 'linked') {
            $dataArray['success']  = false;
            $dataArray['message']  = $this->translator->trans('le.email.delete.verified.email.linked');
        } else {
            $dataArray['success']  = false;
            $dataArray['message']  = $this->translator->trans('le.email.delete.verified.email');
        }

        return $this->sendJsonResponse($dataArray);
    }

    public function reVerifySenderProfileAction(Request $request)
    {
        $fromemail     = InputHelper::clean($request->request->get('email'));
        $fromname      = InputHelper::clean($request->request->get('name'));
        $response      =$this->verifyNewSenderProfile($fromemail, $fromname, 'updated');

        return $this->sendJsonResponse($response);
    }

    public function DisableAllSenderProfileAction(Request $request)
    {
        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel       = $this->factory->getModel('email');
        $emailModel->resetAllSenderProfiles();
        $dataArray['success']  =true;

        return $this->sendJsonResponse($dataArray);
    }

    public function emailstatusAction()
    {
        $isClosed                     = $this->factory->get('session')->get('isalert_needed', 'false');
        $isStateAlive                 =$this->get('le.helper.statemachine')->isStateAlive('Trial_Unverified_Email');
        //   $configurl                    =$this->factory->getRouter()->generate('le_config_action', ['objectAction' => 'edit', 'objectId'=> 'sendingdomain_config']);
        if ($isStateAlive) {
            $dataArray['success']       = true;
            $dataArray['info']          = $this->translator->trans('le.email.unverified.error');
            $dataArray['isalertneeded'] = $isClosed;
        } else {
            $dataArray['success']       = false;
            $dataArray['info']          = '';
            $dataArray['isalertneeded'] = $isClosed;
        }

        return $this->sendJsonResponse($dataArray);
    }

    public function createDripEmailsAction(Request $request)
    {
        $data = $request->request->all();

        /** @var \Mautic\UserBundle\Model\UserModel $usermodel */
        $usermodel     = $this->getModel('user.user');
        $userentity    = $usermodel->getCurrentUserEntity();

        /** @var DripEmailModel $dripModel */
        $dripModel     = $this->getModel('email.dripemail');

        $subject      = $data['subject'];
        $previewText  = $data['previewText'];
        $customHtml   = $data['customHtml'];
        $beeJson      = $data['beeJson'];
        $dripEmail    = $data['dripEntity'];

        $dripEntity    = $dripModel->getEntity($dripEmail);

        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');

        /** @var \Mautic\EmailBundle\Entity\Email $emailentity */
        $emailentity = $emailmodel->getEntity();

        $emailentity->setName('DripEmail - '.$subject);
        $emailentity->setSubject($subject);
        $emailentity->setPreviewText($previewText);
        $emailentity->setCustomHtml($customHtml);
        $emailentity->setBeeJSON($beeJson);
        $emailentity->setDripEmail($dripEntity);
        $emailentity->setCreatedBy($userentity);
        $emailentity->setIsPublished(true);
        $emailentity->setGoogleTags(true);
        $emailentity->setEmailType('dripemail');

        /** @var \Mautic\CoreBundle\Configurator\Configurator $configurator */
        $configurator     = $this->get('mautic.configurator');
        $params           = $configurator->getParameters();
        $fromname         = $params['mailer_from_name'];
        $fromaddress      = $params['mailer_from_email'];
//        $defaultsender=$emailmodel->getDefaultSenderProfile();
//        if (sizeof($defaultsender) > 0) {
//            $emailentity->setFromName($defaultsender[0]);
//            $emailentity->setFromAddress($defaultsender[1]);
//        }
        $emailentity->setFromName($fromname);
        $emailentity->setFromAddress($fromaddress);
        $totalitems = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripEntity,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );
        $emailentity->setDripEmailOrder(sizeof($totalitems) + 1);
        $scheduleTime = '0 days';
        if (sizeof($totalitems) > 0) {
            $scheduleTime = '1 days';
        }
        $emailentity->setScheduleTime($scheduleTime);
        $emailmodel->saveEntity($emailentity);

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'email:emails:viewown',
                'email:emails:viewother',
                'email:emails:create',
                'email:emails:editown',
                'email:emails:editother',
                'email:emails:deleteown',
                'email:emails:deleteother',
                'email:emails:publishown',
                'email:emails:publishother',
            ],
            'RETURN_ARRAY'
        );

        $items = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripEntity,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );
        $htmlContent = $this->render('MauticEmailBundle:DripEmail:emaillist.html.php', [
            'items'             => $items,
            'permissions'       => $permissions,
            'actionRoute'       => 'le_dripemail_campaign_action',
            'translationBase'   => 'mautic.email.broadcast',
            'entity'            => $dripEntity,
        ]);
        $htmlContent = strstr($htmlContent, '<div class="table-responsive">');

        $responseArr             = [];
        $responseArr['success']  = true;
        $responseArr['content']  = $htmlContent;

        return $this->sendJsonResponse($responseArr);
    }

    public function deleteDripEmailsAction(Request $request)
    {
        $data = $request->request->all();

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'email:emails:viewown',
                'email:emails:viewother',
                'email:emails:create',
                'email:emails:editown',
                'email:emails:editother',
                'email:emails:deleteown',
                'email:emails:deleteother',
                'email:emails:publishown',
                'email:emails:publishother',
            ],
            'RETURN_ARRAY'
        );

        $emailId      = $data['emailId'];
        $dripEmail    = $data['dripEmail'];

        /** @var DripEmailModel $dripModel */
        $dripModel     = $this->getModel('email.dripemail');
        $dripEntity    = $dripModel->getEntity($dripEmail);
        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');
        $entity     = $emailmodel->getEntity($emailId);
        /** @var LeadEventLogRepository $leadEventLog */
        $leadEventLog  = $this->get('mautic.email.repository.leadEventLog');
        $leadEvents    = $leadEventLog->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'dle.email',
                            'expr'   => 'eq',
                            'value'  => $entity,
                        ],
                    ],
                ],
                'ignore_paginator' => true,
            ]
        );
        foreach ($leadEvents as $event) {
            $leadEventLog->deleteEntity($event);
        }
        $emailmodel->deleteEntity($entity);

        $items = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripEntity,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );
        $emailOrder = 0;
        foreach ($items as $item) {
            $emailOrder = $emailOrder + 1;
            $item->setDripEmailOrder($emailOrder);
            $emailmodel->saveEntity($item);
        }

        $items = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripEntity,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );

        $htmlContent = $this->render('MauticEmailBundle:DripEmail:emaillist.html.php', [
            'items'             => $items,
            'permissions'       => $permissions,
            'actionRoute'       => 'le_dripemail_campaign_action',
            'translationBase'   => 'mautic.email.broadcast',
            'entity'            => $dripEntity,
        ]);

        if (strpos($htmlContent, '<div class="table-responsive">') !== false) {
            $htmlContent = strstr($htmlContent, '<div class="table-responsive">');
        } elseif (strpos($htmlContent, '<p class="drip-col-stats">') !== false) {
            $htmlContent = strstr($htmlContent, '<div class="col-md-8 col-md-offset-2 mt-md bluprint" style="white-space: normal;">');
        }
        $responseArr                = [];
        $responseArr['success']     = true;
        $responseArr['content']     = $htmlContent;

        return $this->sendJsonResponse($responseArr);
    }

    public function updateDripEmailFrequencyAction(Request $request)
    {
        $data = $request->request->all();

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'email:emails:viewown',
                'email:emails:viewother',
                'email:emails:create',
                'email:emails:editown',
                'email:emails:editother',
                'email:emails:deleteown',
                'email:emails:deleteother',
                'email:emails:publishown',
                'email:emails:publishother',
            ],
            'RETURN_ARRAY'
        );

        $frequencyUnit   = $data['frequencyUnit'];
        $frequencyValue  = $data['frequencyValue'];
        $emailId         = $data['EmailId'];
        $scheduleTime    = $frequencyValue.' '.$frequencyUnit;

        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');
        $entity     = $emailmodel->getEntity($emailId);
        $entity->setScheduleTime($scheduleTime);
        $emailmodel->saveEntity($entity);

        /** @var DripEmailModel $dripModel */
        $dripModel     = $this->getModel('email.dripemail');

        $dripEntity    = $dripModel->getEntity($entity->getDripEmail()->getId());

        $items = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripEntity,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );

        $htmlContent = $this->render('MauticEmailBundle:DripEmail:emaillist.html.php', [
            'items'             => $items,
            'permissions'       => $permissions,
            'actionRoute'       => 'le_dripemail_campaign_action',
            'translationBase'   => 'mautic.email.broadcast',
            'entity'            => $dripEntity,
        ]);

        $htmlContent = strstr($htmlContent, '<div class="table-responsive">');

        $responseArr             = [];
        $responseArr['success']  = true;
        $responseArr['content']  = $htmlContent;

        return $this->sendJsonResponse($responseArr);
    }

    /**
     * @param Request $request
     */
    protected function getDripEmailScheduledCountStatsAction(Request $request)
    {
        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');

        /** @var DripEmailModel $model */
        $model = $this->getModel('email.dripemail');

        $data = [];
        if ($id = $request->get('id')) {
            if ($email = $emailmodel->getEntity($id)) {
                $eventLogRepo  = $model->getCampaignLeadEventRepository();
                $events        = $eventLogRepo->getScheduledEventsbyDripEmail($email);
                $scheduledlead = sizeof($events);
                $data          = [
                    'success'        => 1,
                    'scheduledcount' => $this->translator->trans('le.drip.email.stat.scheduledcount', ['%count%' =>$scheduledlead]),
                ];
            }
        }

        return new JsonResponse($data);
    }

    /**
     * @param Request $request
     */
    protected function getDripEmailStatsAction(Request $request)
    {
        /** @var DripEmailModel $model */
        $model = $this->getModel('email.dripemail');

        $id  = $request->get('id');
        $ids = $request->get('ids');
        // Support for legacy calls
        if (!$ids && $id) {
            $ids = [$id];
        }
        $data = $model->getDripEmailStats($ids, true, $request);

        return new JsonResponse($data);
    }

    public function updateDripEmailsAction(Request $request)
    {
        $data = $request->request->all();

        /** @var DripEmailModel $dripModel */
        $dripModel     = $this->getModel('email.dripemail');

        $subject      = $data['subject'];
        $previewText  = $data['previewText'];
        $customHtml   = $data['customHtml'];
        $Emailid      = $data['Emailid'];

        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');

        /** @var \Mautic\EmailBundle\Entity\Email $emailentity */
        $emailentity = $emailmodel->getEntity($Emailid);

        $emailentity->setSubject($subject);
        $emailentity->setPreviewText($previewText);
        $emailentity->setCustomHtml($customHtml);
        $dripEntity = $emailentity->getDripEmail();
        $emailmodel->saveEntity($emailentity);

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'email:emails:viewown',
                'email:emails:viewother',
                'email:emails:create',
                'email:emails:editown',
                'email:emails:editother',
                'email:emails:deleteown',
                'email:emails:deleteother',
                'email:emails:publishown',
                'email:emails:publishother',
            ],
            'RETURN_ARRAY'
        );

        $items = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripEntity,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );
        $htmlContent = $this->render('MauticEmailBundle:DripEmail:emaillist.html.php', [
            'items'             => $items,
            'permissions'       => $permissions,
            'actionRoute'       => 'le_dripemail_campaign_action',
            'translationBase'   => 'mautic.email.broadcast',
            'entity'            => $dripEntity,
        ]);
        $htmlContent = strstr($htmlContent, '<div class="table-responsive">');

        $responseArr             = [];
        $responseArr['success']  = true;
        $responseArr['content']  = $htmlContent;

        return $this->sendJsonResponse($responseArr);
    }

    public function getEmailDetailsAction(Request $request)
    {
        $data     = $request->request->all();
        $Emailid  = $data['Emailid'];
        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');

        /** @var \Mautic\EmailBundle\Entity\Email $emailentity */
        $emailentity = $emailmodel->getEntity($Emailid);
        if (!empty($emailentity)) {
            $responseArr                 = [];
            $responseArr['success']      = true;
            $responseArr['subject']      = $emailentity->getSubject();
            $responseArr['emailcontent'] = $emailentity->getCustomHtml();
            $responseArr['beeJSON']      = $emailentity->getBeeJSON();
            $responseArr['preview']      = $emailentity->getPreviewText();
            $responseArr['Emailid']      = $emailentity->getId();
            $responseArr['isBeeEditor']  = empty($emailentity->getBeeJSON()) ? false : true;
        } else {
            $responseArr             = [];
            $responseArr['success']  = false;
        }

        return $this->sendJsonResponse($responseArr);
    }

    public function createBlueprintEmailsAction(Request $request)
    {
        $data        = $request->request->all();
        $dripId      = $data['dripId'];
        $currentId   = $data['currentId'];
        $driprepo    = $this->get('le.core.repository.signup');

        /** @var DripEmailModel $dripmodel */
        $dripmodel = $this->getModel('email.dripemail');
        $dripemail = $dripmodel->getEntity($currentId);
        $items     = $driprepo->getEmailsByDripId($dripId);
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'email:emails:viewown',
                'email:emails:viewother',
                'email:emails:create',
                'email:emails:editown',
                'email:emails:editother',
                'email:emails:deleteown',
                'email:emails:deleteother',
                'email:emails:publishown',
                'email:emails:publishother',
            ],
            'RETURN_ARRAY'
        );

        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');
        /** @var \Mautic\UserBundle\Model\UserModel $usermodel */
        $usermodel     = $this->getModel('user.user');
        $userentity    = $usermodel->getCurrentUserEntity();

        $dripOrder = 0;
        foreach ($items as $item) {
            $dripOrder = $dripOrder + 1;
            $newEntity = $emailmodel->getEntity();
            //file_put_contents("/var/www/log.txt",$item->getName()."\n",FILE_APPEND);
            $newEntity->setName($item['name']);
            $newEntity->setSubject($item['subject']);
            $newEntity->setPreviewText($item['preview_text']);
            $newEntity->setCustomHtml($item['custom_html']);
            $newEntity->setBeeJSON($item['bee_json']);
            $newEntity->setDripEmail($dripemail);
            $newEntity->setCreatedBy($userentity);
            $newEntity->setIsPublished(true);
            $newEntity->setGoogleTags(true);
            $newEntity->setEmailType('dripemail');
            /** @var \Mautic\CoreBundle\Configurator\Configurator $configurator */
            $configurator= $this->get('mautic.configurator');

            $params           = $configurator->getParameters();
            $fromname         = $params['mailer_from_name'];
            $fromaddress      = $params['mailer_from_email'];
//            $defaultsender=$emailmodel->getDefaultSenderProfile();
//            if (sizeof($defaultsender) > 0) {
//                $newEntity->setFromName($defaultsender[0]);
//                $newEntity->setFromAddress($defaultsender[1]);
//            }
            $newEntity->setFromName($fromname);
            $newEntity->setFromAddress($fromaddress);
            $newEntity->setDripEmailOrder($dripOrder);
            $newEntity->setScheduleTime($item['scheduleTime']);
            $emailmodel->saveEntity($newEntity);
        }
        $items = $emailmodel->getEntities(
            [
                'filter'           => [
                    'force' => [
                        [
                            'column' => 'e.dripEmail',
                            'expr'   => 'eq',
                            'value'  => $dripemail,
                        ],
                    ],
                ],
                'orderBy'          => 'e.dripEmailOrder',
                'orderByDir'       => 'asc',
                'ignore_paginator' => true,
            ]
        );
        $htmlContent = $this->render('MauticEmailBundle:DripEmail:emaillist.html.php', [
            'items'             => $items,
            'permissions'       => $permissions,
            'actionRoute'       => 'le_dripemail_campaign_action',
            'translationBase'   => 'mautic.email.broadcast',
            'entity'            => $dripemail,
        ]);
        $htmlContent = strstr($htmlContent, '<div class="table-responsive">');

        $responseArr             = [];
        $responseArr['success']  = true;
        $responseArr['content']  = $htmlContent;

        return $this->sendJsonResponse($responseArr);
    }

    public function reorderEmailsAction(Request $request)
    {
        $data      = $request->request->all();
        $emailId   = $data['id'];
        $order     = $data['order'];
        $totalsize = $data['totalsize'];

        /** @var EmailModel $emailmodel */
        $emailmodel = $this->getModel('email');
        $entity     = $emailmodel->getEntity($emailId);
        $entity->setDripEmailOrder($order);
        $emailmodel->saveEntity($entity);

        $responseArr             = [];
        $responseArr['success']  = true;

        if ($totalsize == $order) {
            $this->addFlash($this->translator->trans('le.drip.emails.reorder.message'));
        }
        //render flashes
        $responseArr['flashes'] = $this->getFlashContent();
        //$responseArr['orderChanged'] = $order;

        return $this->sendJsonResponse($responseArr);
    }

    /**
     * @param Request $request
     */
    protected function getEmailsinDripCountStatsAction(Request $request)
    {
        /** @var EmailModel $model */
        $model = $this->getModel('email');

        /** @var DripEmailModel $dripmodel */
        $dripmodel = $this->getModel('email.dripemail');

        $data = [];
        if ($id = $request->get('id')) {
            if ($email = $model->getEntity($id)) {
                $sentCount   = $email->getSentCount(true);

                $clickCount = $model->getEmailClickCount($email->getId());
                if ($clickCount > 0 && $sentCount > 0) {
                    $clickCountPercentage = round($clickCount / $sentCount * 100);
                } else {
                    $clickCountPercentage = 0;
                    $clickCount           =0;
                }

                $eventLogRepo  = $dripmodel->getCampaignLeadEventRepository();
                $events        = $eventLogRepo->getScheduledEventsbyDripEmail($email);
                $scheduledlead = sizeof($events);

                $data = [
                    'success'          => 1,
                    'sentCount'        => $this->translator->trans('le.drip.email.stat.sentcount', ['%count%' =>$sentCount]),
                    'readCount'        => $this->translator->trans('le.email.stat.readcount', ['%count%' => $email->getReadCount(true), '%percentage%' => round($email->getReadPercentage(true))]),
                    'readPercent'      => $this->translator->trans('le.email.stat.readpercent', ['%count%' => $clickCount, '%percentage%'=>$clickCountPercentage]),
                    'scheduledcount'   => $this->translator->trans('le.drip.email.stat.scheduledcount', ['%count%' =>$scheduledlead]),
                ];
            }
        }

        return new JsonResponse($data);
    }

    /**
     * @param Request $request
     */
    protected function getEmailsViewDripCountStatsAction(Request $request)
    {
        /** @var EmailModel $model */
        $model = $this->getModel('email');

        /** @var DripEmailModel $dripmodel */
        $dripmodel = $this->getModel('email.dripemail');

        $data = [];
        if ($id = $request->get('id')) {
            if ($email = $model->getEntity($id)) {
                $sentCount   = $email->getSentCount(true);

                $clickCount = $model->getEmailClickCount($email->getId());
                if ($clickCount > 0 && $sentCount > 0) {
                    $clickCountPercentage = round($clickCount / $sentCount * 100);
                } else {
                    $clickCountPercentage = 0;
                    $clickCount           =0;
                }

                $eventLogRepo     = $dripmodel->getCampaignLeadEventRepository();
                $events           = $eventLogRepo->getScheduledEventsbyDripEmail($email);
                $scheduledlead    = sizeof($events);
                $readCount        = $email->getReadCount(true);
                $unsubscribeCount = $email->getUnsubscribeCount(true);
                $bounceCount      = $email->getBounceCount(true);
                $spamCount        = $email->getSpamCount(true);
                $notreadcount     = $sentCount != 0 ? ($sentCount - $readCount) : 0;
                $failedCount      = $model->getEmailFailedCount($email->getId());

                $dripclickCountPercentage    = 0;
                $dripreadCountPercentage     = 0;
                $dripunsubsCountPercentage   = 0;
                $dripbounceCountPercentage   = 0;
                $dripspamCountPercentage     = 0;
                $dripfailedCountPercentage   = 0;
                $dripnotopenedCountPercentage= 0;
                if ($clickCount > 0 && $sentCount > 0) {
                    $dripclickCountPercentage  = round($clickCount / $sentCount * 100);
                }
                if ($readCount > 0 && $sentCount > 0) {
                    $dripreadCountPercentage   = round($readCount / $sentCount * 100);
                }
                if ($notreadcount > 0 && $sentCount > 0) {
                    $dripnotopenedCountPercentage   = round($notreadcount / $sentCount * 100);
                }
                if ($unsubscribeCount > 0 && $sentCount > 0) {
                    $dripunsubsCountPercentage = round($unsubscribeCount / $sentCount * 100);
                }
                if ($bounceCount > 0 && $sentCount > 0) {
                    $dripbounceCountPercentage = round($bounceCount / $sentCount * 100);
                }
                if ($spamCount > 0 && $sentCount > 0) {
                    $dripspamCountPercentage = round($spamCount / $sentCount * 100);
                }
                if ($failedCount > 0 && $scheduledlead > 0) {
                    $dripfailedCountPercentage = round($failedCount / $scheduledlead * 100);
                }

                $data             = [
                    'success'            => 1,
                    'sentCount'          => $this->translator->trans('le.drip.email.stat.sentcount', ['%count%' =>$sentCount]),
                    'readCount'          => $this->translator->trans('le.drip.email.stat.readcount', ['%count%' => $readCount, '%percentage%' => $dripreadCountPercentage]),
                    'readPercent'        => $this->translator->trans('le.drip.email.stat.clickcount', ['%count%' => $clickCount, '%percentage%' => $dripclickCountPercentage]),
                    'noreadCount'        => $this->translator->trans('le.drip.email.stat.notopencount', ['%count%' =>$notreadcount, '%percentage%' => $dripnotopenedCountPercentage]),
                    'unsubscribeCount'   => $this->translator->trans('le.drip.email.stat.unsubscribecount', ['%count%' =>$unsubscribeCount, '%percentage%' => $dripunsubsCountPercentage]),
                    'bounceCount'        => $this->translator->trans('le.drip.email.stat.bouncecount', ['%count%' =>$bounceCount, '%percentage%' => $dripbounceCountPercentage]),
                    'spamCount'          => $this->translator->trans('le.drip.email.stat.spamcount', ['%count%' =>$spamCount, '%percentage%' => $dripspamCountPercentage]),
                    'failedCount'        => $this->translator->trans('le.drip.email.stat.failedcount', ['%count%' =>$failedCount, '%percentage%' => $dripfailedCountPercentage]),
                ];
            }
        }

        return new JsonResponse($data);
    }

    protected function senderDomainCreateAction(Request $request)
    {
        $domain     = InputHelper::clean($request->request->get('domain'));
        $domain     =strtolower($domain);
        /** @var EmailModel $model */
        $model               = $this->getModel('email');
        $elasticApiHelper    = $this->get('mautic.helper.elasticapi');
        $domainList          = $model->getRepository()->getAllSendingDomains($domain);
        $content             = '';
        $message             = '';
        $status              = true;
        $smHelper            = $this->get('le.helper.statemachine');
        $coreParameterHelper = $this->get('mautic.helper.core_parameters');
        $elaticApiKey        =$coreParameterHelper->getParameter('mailer_password');
        if ($elaticApiKey == 'le_trial_password') {
            $elaticApiKey=$smHelper->createElasticSubAccountandAssign();
            if (!$elaticApiKey) {
                $status =false;
                $message=$this->translator->trans('le.message.technical.error');
            }
        }
        if ($elaticApiKey) {
            if (sizeof($domainList) == 0) {
                $domainCreated=$elasticApiHelper->createDomain($domain, $elaticApiKey);
                if ($domainCreated[0]) {
                    $newSendingDomain=new SendingDomain();
                    $newSendingDomain->setDomain($domain);
                    $newSendingDomain->setdkimCheck(false);
                    $newSendingDomain->setspfCheck(false);
                    $newSendingDomain->settrackingCheck(false);
                    $newSendingDomain->setmxCheck(false);
                    $newSendingDomain->setdmarcCheck(false);
                    $newSendingDomain->setStatus(false);
                    $newSendingDomain->setIsDefault(false);
                    $model->getRepository()->saveEntity($newSendingDomain);
                    $domainList=$model->getRepository()->getAllSendingDomains();
                    $content   = $this->renderView('MauticEmailBundle:Config:sendingdomainlist.html.php', [
                        'sendingdomains'             => $domainList,
                    ]);
                } else {
                    $status =false;
                    if (strpos($domainCreated[1], 'currently blacklisted')) {
                        $message=$this->translator->trans('le.email.sending.domain.black.listed.alert', ['%domain%'=>$domain]);
                    } else {
                        $message=$domainCreated[1]; //$this->translator->trans('le.message.technical.error');
                    }
                }
            } else {
                $status =false;
                $message=$this->translator->trans('le.email.sending.domain.already.exist.alert');
            }
        }
        $data             = ['success'            => $status, 'content'=>$content, 'message'=>$message];

        return $this->sendJsonResponse($data);
    }

    protected function senderDomainDeleteAction(Request $request)
    {
        $status        =true;
        $content       ='';
        $message       ='';
        $domainVal     = InputHelper::clean($request->request->get('domain'));
        /** @var EmailModel $model */
        $model            = $this->getModel('email');
        $elasticApiHelper = $this->get('mautic.helper.elasticapi');
        $domainList       =$model->getRepository()->getAllSendingDomains($domainVal);
        foreach ($domainList as $domain) {
            if ($domain->getIsDefault()) {
                $status =false;
                $message=$this->translator->trans('le.email.default.sending.domain.delete.alert');
            } else {
                $deleteStatus     =$elasticApiHelper->deleteDomain($domainVal);
                if ($deleteStatus) {
                    $model->getRepository()->deleteEntity($domain);
                } else {
                    $status =false;
                    $message=$this->translator->trans('le.message.technical.error');
                }
            }
            break;
        }
        $domainList         =$model->getRepository()->getAllSendingDomains();
        $isAnyDomainVerified=false;
        foreach ($domainList as $sendingdomain) {
            if ($sendingdomain->getStatus()) {
                $isAnyDomainVerified=true;
                break;
            }
        }
        if (!$isAnyDomainVerified) {
            $smHelper            = $this->get('le.helper.statemachine');
            $paymentrepository   =$this->get('le.subscription.repository.payment');
            $lastpayment         = $paymentrepository->getLastPayment();
            $prefix              = 'Trial';
            if ($lastpayment != null) {
                $prefix = 'Customer';
            }
            if (!$smHelper->isStateAlive($prefix.'_Sending_Domain_Not_Configured') && !$smHelper->isStateAlive($prefix.'_Inactive_Sending_Domain_Issue')) {
                $smHelper->makeStateInActive([$prefix.'_Active']);
                $smHelper->newStateEntry($prefix.'_Inactive_Sending_Domain_Issue', '');
                $smHelper->addStateWithLead();
                $smHelper->sendSendingDomainIssueEmail();
            }
        }
        $content   = $this->renderView('MauticEmailBundle:Config:sendingdomainlist.html.php', [
            'sendingdomains'             => $domainList,
        ]);
        $data             = ['success'            => $status, 'content'=>$content, 'message'=>$message];

        return $this->sendJsonResponse($data);
    }

    protected function senderDomainValidationAction(Request $request)
    {
        $domain     = InputHelper::clean($request->request->get('domain'));
        /** @var EmailModel $model */
        $model            = $this->getModel('email');
        $elasticApiHelper = $this->get('mautic.helper.elasticapi');
        $spf_check        =$elasticApiHelper->verifySPF($domain);
        $dkim_check       =$elasticApiHelper->verifyDKIM($domain);
        $dmark_check      =$elasticApiHelper->verifyDMARK($domain);
        $tracking_check   = false; //$elasticApiHelper->verifyTracking($domain);
        $domainList       =$model->getRepository()->getAllSendingDomains($domain);
        foreach ($domainList as $sendingdomain) {
            $sendingdomain->setdkimCheck($dkim_check);
            $sendingdomain->setspfCheck($spf_check);
            $sendingdomain->settrackingCheck($tracking_check);
            $sendingdomain->setdmarcCheck($dmark_check);
            $sendingdomain->setStatus($dkim_check && $spf_check);
            if (!$this->isAnyDefaultDomainSet() && $sendingdomain->getStatus()) {
                $sendingdomain->setIsDefault(true);
                $elasticApiHelper->setDefaultDomain($domain);
            }
            $model->getRepository()->saveEntity($sendingdomain);
        }
        $domainList          =$model->getRepository()->getAllSendingDomains();
        $smHelper            = $this->get('le.helper.statemachine');
        $paymentrepository   =$this->get('le.subscription.repository.payment');
        $lastpayment         = $paymentrepository->getLastPayment();
        $prefix              = 'Trial';
        if ($lastpayment != null) {
            $prefix = 'Customer';
        }
        if ($smHelper->isStateAlive($prefix.'_Sending_Domain_Not_Configured') || $smHelper->isStateAlive($prefix.'_Inactive_Sending_Domain_Issue')) {
            $isAnyDomainVerified=false;
            foreach ($domainList as $sendingdomain) {
                if ($sendingdomain->getStatus()) {
                    $isAnyDomainVerified=true;
                    break;
                }
            }
            $removeRestriction = false;
            if ($isAnyDomainVerified) {
                if ($smHelper->isStateAlive($prefix.'_Sending_Domain_Not_Configured')) {
                    $smHelper->sendInternalSlackMessage('sending_domain_configured');
                    $removeRestriction = true;
                }
                $smHelper->makeStateInActive([$prefix.'_Sending_Domain_Not_Configured', $prefix.'_Inactive_Sending_Domain_Issue']);
                if (!$smHelper->isAnyInActiveStateAlive()) {
                    $smHelper->newStateEntry($prefix.'_Active', '');
                    if ($removeRestriction) {
                        $licenseinfoHelper = $this->get('mautic.helper.licenseinfo');
                        $license           = $licenseinfoHelper->getLicenseEntity();
                        $licenseRepo       = $licenseinfoHelper->getLicenseRepository();
                        $license->setTotalRecordCount('UL');
                        $license->setTotalEmailCount('100000');
                        $licenseRepo->saveEntity($license);
                    }
                }
                $smHelper->addStateWithLead();
            }
        }
        $content   = $this->renderView('MauticEmailBundle:Config:sendingdomainlist.html.php', [
            'sendingdomains'             => $domainList,
        ]);
        $data             = ['success'            => true, 'spf_check'=>$spf_check, 'dkim_check'=>$dkim_check, 'dmark_check'=>$dmark_check, 'tracking_check'=>$tracking_check, 'content'=>$content];

        return $this->sendJsonResponse($data);
    }

    protected function isAnyDefaultDomainSet()
    {
        /** @var EmailModel $model */
        $model               = $this->getModel('email');
        $domainList          =$model->getRepository()->getAllSendingDomains();
        $defaultset          =false;
        foreach ($domainList as $sendingdomain) {
            if ($sendingdomain->getStatus() && $sendingdomain->getIsDefault()) {
                $defaultset=true;
            }
        }

        return $defaultset;
    }

    protected function senderDomainDefaultAction(Request $request)
    {
        $status     =true;
        $message    ='';
        $domain     = InputHelper::clean($request->request->get('domain'));
        /** @var EmailModel $model */
        $model     = $this->getModel('email');
        $domainList=$model->getRepository()->getAllSendingDomains($domain);
        foreach ($domainList as $sendingdomain) {
            if (!$sendingdomain->getStatus()) {
                $status =false;
                $message=$this->translator->trans('le.email.sending.domain.verify.alert');
            }
            break;
        }
        $content='';
        if ($status) {
            $domainList=$model->getRepository()->getAllSendingDomains();
            foreach ($domainList as $sendingdomain) {
                if ($sendingdomain->getDomain() == $domain) {
                    $sendingdomain->setIsDefault(true);

                    $smHelper            =$this->get('le.helper.statemachine');
                    $paymentrepository   =$this->get('le.subscription.repository.payment');
                    $lastpayment         = $paymentrepository->getLastPayment();
                    $prefix              = 'Trial';
                    if ($lastpayment != null) {
                        $prefix = 'Customer';
                    }
                    if ($smHelper->isStateAlive($prefix.'_Sending_Domain_Not_Configured') || $smHelper->isStateAlive($prefix.'_Inactive_Sending_Domain_Issue')) {
                        $smHelper->makeStateInActive([$prefix.'_Sending_Domain_Not_Configured', $prefix.'_Inactive_Sending_Domain_Issue']);
                        if (!$smHelper->isAnyInActiveStateAlive()) {
                            $smHelper->newStateEntry($prefix.'_Active', '');
                        }
                    }
                    $elasticApiHelper    = $this->get('mautic.helper.elasticapi');
                    $response            =$elasticApiHelper->setDefaultDomain($domain);
                } else {
                    $sendingdomain->setIsDefault(false);
                }
                $model->getRepository()->saveEntity($sendingdomain);
            }
            $content = $this->renderView('MauticEmailBundle:Config:sendingdomainlist.html.php', [
                'sendingdomains'             => $domainList,
            ]);
        }
        $data             = ['success'            => $status, 'content'=>$content, 'message'=>$message];

        return $this->sendJsonResponse($data);
    }
}
