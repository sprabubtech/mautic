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

use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\EmojiHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\EmailBundle\BeeEditor\BeeFree;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\Swiftmailer\Transport\CallbackTransportInterface;
use Mautic\EmailBundle\Swiftmailer\Transport\InterfaceCallbackTransport;
use Mautic\LeadBundle\Controller\FrequencyRuleTrait;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Event\PageDisplayEvent;
use Mautic\PageBundle\EventListener\BuilderSubscriber;
use Mautic\PageBundle\PageEvents;
use Mautic\QueueBundle\Queue\QueueName;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    use FrequencyRuleTrait;

    /**
     * @param $idHash
     *
     * @return Response
     */
    public function indexAction($idHash)
    {
        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');
        $stat  = $model->getEmailStatus($idHash);

        if (!empty($stat)) {
            if ($this->get('mautic.security')->isAnonymous()) {
                $model->hitEmail($stat, $this->request, true);
            }

            $tokens = $stat->getTokens();
            if (is_array($tokens)) {
                // Override tracking_pixel so as to not cause a double hit
                $tokens['{tracking_pixel}'] = MailHelper::getBlankPixel();
            }

            if ($copy = $stat->getStoredCopy()) {
                $subject = $copy->getSubject();
                $content = $copy->getBody();

                // Convert emoji
                $content = EmojiHelper::toEmoji($content, 'short');
                $subject = EmojiHelper::toEmoji($subject, 'short');

                // Replace tokens
                if (!empty($tokens)) {
                    $content = str_ireplace(array_keys($tokens), $tokens, $content);
                    $subject = str_ireplace(array_keys($tokens), $tokens, $subject);
                }
            } else {
                $subject = '';
                $content = '';
            }

            // Add analytics
            $analytics = $this->factory->getHelper('template.analytics')->getCode();

            // Check for html doc
            if (strpos($content, '<html') === false) {
                $content = "<html>\n<head>{$analytics}</head>\n<body>{$content}</body>\n</html>";
            } elseif (strpos($content, '<head>') === false) {
                $content = str_replace('<html>', "<html>\n<head>\n{$analytics}\n</head>", $content);
            } elseif (!empty($analytics)) {
                $content = str_replace('</head>', $analytics."\n</head>", $content);
            }

            // Add subject as title
            if (!empty($subject)) {
                if (strpos($content, '<title></title>') !== false) {
                    $content = str_replace('<title></title>', "<title>$subject</title>", $content);
                } elseif (strpos($content, '<title>') === false) {
                    $content = str_replace('<head>', "<head>\n<title>$subject</title>", $content);
                }
            }

            return new Response($content);
        }
        $content = $this->translator->trans('le.email.stat_record.not_found');

        return $this->render('MauticEmailBundle:Email:notfound.html.php', ['content' => $content]);
    }

    /**
     * @param $idHash
     *
     * @return Response
     */
    public function trackingImageAction($idHash)
    {
        $queueService = $this->get('mautic.queue.service');
        if ($queueService->isQueueEnabled()) {
            $msg = [
                'request' => $this->request,
                'idHash'  => $idHash,
            ];
            $queueService->publishToQueue(QueueName::EMAIL_HIT, $msg);
        } else {
            /** @var EmailModel $model */
            $model = $this->getModel('email');
            $model->hitEmail($idHash, $this->request);
        }

        return TrackingPixelHelper::getResponse($this->request);
    }

    /**
     * @param $idHash
     *
     * @throws \Exception
     * @throws \Mautic\CoreBundle\Exception\FileNotFoundException
     */
    public function updateleadAction($idHash)
    {
        $model       = $this->getModel('email');
        $emailRepo   = $model->getRepository();
        $stat        = $model->getEmailStatus($idHash);
        $formContent = '';
        $leadId      = '';
        $message     = '';
        $email       = '';
        $actionName  = '';

        if (!empty($stat)) {
            if ($email = $stat->getEmailAddress()) {
                $lead = $stat->getLead();
                if (!empty($lead)) {
                    $leadId = $lead->getId();
                } else {
                    $message = $this->translator->trans('le.email.stat_record.not_found');
                }
            }
            $formContent = '';
        } else {
            $message = $this->translator->trans('le.email.stat_record.not_found');
        }

        $actionRoute = $this->generateUrl('le_email_updatelead', ['idHash' => $idHash]);

        if ($this->request->getMethod() == 'POST') {
            $parameter       = $this->request->request->all();
            $firstName       = $parameter['firstname'];
            $lastName        = $parameter['lastname'];
            $newEmailAddress = $parameter['emailaddress'];

            if (!empty($firstName) && !empty($lastName) && !empty($newEmailAddress)) {
                $emailRepo->updateLeadDetails($firstName, $lastName, $newEmailAddress, $leadId);
            }
            $viewParams['content'] = $formContent;

            $contentTemplate       = 'MauticEmailBundle:Email:updatelead.html.php';
            $viewParams            = [
                'email'       => $email,
                'message'     => $message,
                'actionroute' => $actionRoute,
                'actionName'  => 'viewlead',
                'idHash'      => $idHash,
            ];

            return $this->render($contentTemplate, $viewParams);
        }

        if (!empty($email)) {
            // Replacing email id with star
            $position = strrpos($email, '@');
            $result1  = substr($email, 0, $position);
            $result2  = substr($email, $position);
            $len      = strlen($result1);
            $result1  = substr($result1, 0, 1).str_repeat('*', $len - 2).substr($result1, $len - 1, 1);
            $email    = $result1.$result2;
        }

        if (empty($message)) {
            $actionName = 'updatelead';
        }
        $viewParams  = [
            'email'       => $email,
            'message'     => $message,
            'actionroute' => $actionRoute,
            'actionName'  => $actionName,
            'idHash'      => $idHash,
        ];

        $contentTemplate  = 'MauticEmailBundle:Email:updatelead.html.php';

        return $this->render($contentTemplate, $viewParams);
    }

    /**
     * @param $idHash
     *
     * @return Response
     *
     * @throws \Exception
     * @throws \Mautic\CoreBundle\Exception\FileNotFoundException
     */
    public function subscribeAction($idHash)
    {
        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model       = $this->getModel('email');
        $translator  = $this->get('translator');
        $stat        = $model->getEmailStatus($idHash);
        $formContent = '';
        $lead        = '';
        $message     = '';
        $email       = '';
        if (!empty($stat)) {
            if ($email = $stat->getEmailAddress()) {
                $lead = $stat->getLead();
            }
            $formContent = '';
        } else {
            $message = $this->translator->trans('le.email.stat_record.not_found');
        }
        $actionRoute = $this->generateUrl('le_email_unsubscribe', ['idHash' => $idHash]);
        $viewParams  = [
            'name'        => 'UnSubscribe',
            'email'       => $email,
            'lead'        => $lead,
            'message'     => $message,
            'actionroute' => $actionRoute,
            'actionName'  => 'subscribe',
        ];
        $viewParams['content'] = $formContent;
        $contentTemplate       = 'MauticEmailBundle:Email:unsubscribe.html.php';

        return $this->render($contentTemplate, $viewParams);
    }

    /**
     * @param $idHash
     *
     * @return Response
     *
     * @throws \Exception
     * @throws \Mautic\CoreBundle\Exception\FileNotFoundException
     */
    public function unsubscribeAction($idHash)
    {
        // Find the email
        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model      = $this->getModel('email');
        $translator = $this->get('translator');
        $stat       = $model->getEmailStatus($idHash);
        $message    = '';
        $email      = null;
        $lead       = null;
        $template   = null;
        /** @var \Mautic\LeadBundle\Model\LeadModel $leadModel */
        $leadModel = $this->getModel('lead');
        /** @var \Mautic\EmailBundle\Entity\StatRepository $statRepo */
        $statRepo = $model->getStatRepository();

        if (!empty($stat)) {
            if ($email = $stat->getEmail()) {
                $template = $email->getTemplate();
                if ('le_code_mode' === $template) {
                    // Use system default
                    $template = null;
                }

                /** @var \Mautic\FormBundle\Entity\Form $unsubscribeForm */
                $unsubscribeForm = $email->getUnsubscribeForm();
                if ($unsubscribeForm != null && $unsubscribeForm->isPublished()) {
                    $formTemplate = $unsubscribeForm->getTemplate();
                    $formModel    = $this->getModel('form');
                    $formContent  = '<div class="mautic-unsubscribeform">'.$formModel->getContent($unsubscribeForm).'</div>';
                }
            }
        }

        if (empty($template) && empty($formTemplate)) {
            $template = $this->coreParametersHelper->getParameter('theme');
        } elseif (!empty($formTemplate)) {
            $template = $formTemplate;
        }

        $theme = $this->factory->getTheme($template);
        if ($theme->getTheme() != $template) {
            $template = $theme->getTheme();
        }
        $contentTemplate = $this->factory->getHelper('theme')->checkForTwigTemplate(':'.$template.':message.html.php');

        if (!empty($stat)) {
            $lead = $stat->getLead();
            if ($lead) {
                // Set the lead as current lead
                $leadModel->setCurrentLead($lead);
                // Set lead lang
                if ($lead->getPreferredLocale()) {
                    $translator->setLocale($lead->getPreferredLocale());
                }
            }

            if (!$this->get('mautic.helper.core_parameters')->getParameter('show_contact_preferences')) {
                $message = $this->getUnsubscribeMessage($idHash, $model, $stat, $translator);
            } elseif ($lead) {
                $action = $this->generateUrl('le_email_unsubscribe', ['idHash' => $idHash]);

                $viewParameters = [
                    'lead'                         => $lead,
                    'idHash'                       => $idHash,
                    'showContactFrequency'         => $this->get('mautic.helper.core_parameters')->getParameter('show_contact_frequency'),
                    'showContactPauseDates'        => $this->get('mautic.helper.core_parameters')->getParameter('show_contact_pause_dates'),
                    'showContactPreferredChannels' => $this->get('mautic.helper.core_parameters')->getParameter('show_contact_preferred_channels'),
                    'showContactCategories'        => $this->get('mautic.helper.core_parameters')->getParameter('show_contact_categories'),
                    'showContactSegments'          => $this->get('mautic.helper.core_parameters')->getParameter('show_contact_segments'),
                ];

                $form = $this->getFrequencyRuleForm($lead, $viewParameters, $data, true, $action);
                if (true === $form) {
                    return $this->postActionRedirect(
                        [
                            'returnUrl'       => $this->generateUrl('le_email_unsubscribe', ['idHash' => $idHash]),
                            'viewParameters'  => $viewParameters,
                            'contentTemplate' => $contentTemplate,
                        ]
                    );
                }

                $formView = $form->createView();
                /** @var Page $prefCenter */
                if ($email && ($prefCenter = $email->getPreferenceCenter()) && ($prefCenter->getIsPreferenceCenter())) {
                    $html = $prefCenter->getCustomHtml();
                    // check if tokens are present
                    $savePrefsPresent = false !== strpos($html, 'data-slot="saveprefsbutton"') ||
                                        false !== strpos($html, BuilderSubscriber::saveprefsRegex);
                    $frequencyPresent = false !== strpos($html, 'data-slot="channelfrequency"') ||
                                        false !== strpos($html, BuilderSubscriber::channelfrequency);
                    $tokensPresent = $savePrefsPresent && $frequencyPresent;
                    if ($tokensPresent) {
                        // set custom tag to inject end form
                        // update show pref center slots by looking for their presence in the html
                        $params = array_merge(
                            $viewParameters,
                            [
                                'form'                         => $formView,
                                'custom_tag'                   => '<a name="end-'.$formView->vars['id'].'"></a>',
                                'showContactSegments'          => false !== strpos($html, 'data-slot="segmentlist"') || false !== strpos($html, BuilderSubscriber::segmentListRegex),
                                'showContactCategories'        => false !== strpos($html, 'data-slot="categorylist"') || false !== strpos($html, BuilderSubscriber::categoryListRegex),
                                'showContactPreferredChannels' => false !== strpos($html, 'data-slot="preferredchannel"') || false !== strpos($html, BuilderSubscriber::preferredchannel),
                            ]
                        );
                        // Replace tokens in preference center page
                        $event = new PageDisplayEvent($html, $prefCenter, $params);
                        $this->get('event_dispatcher')
                             ->dispatch(PageEvents::PAGE_ON_DISPLAY, $event);
                        $html = $event->getContent();
                        $html = preg_replace('/'.BuilderSubscriber::identifierToken.'/', $lead->getPrimaryIdentifier(), $html);
                    } else {
                        unset($html);
                    }
                }

                if (empty($html)) {
                    $html = $this->get('mautic.helper.templating')->getTemplating()->render(
                        'MauticEmailBundle:Lead:preference_options.html.php',
                        array_merge(
                            $viewParameters,
                            [
                                'form'         => $formView,
                                'currentRoute' => $this->generateUrl(
                                    'le_contact_action',
                                    [
                                        'objectAction' => 'contactFrequency',
                                        'objectId'     => $lead->getId(),
                                    ]
                                ),
                            ]
                        )
                    );
                }
                $message = $html;
            }
            $stat->setIsUnsubscribe(true);
            $statRepo->saveEntity($stat);
        } else {
            $message = $translator->trans('le.email.stat_record.not_found');
        }

        $config = $theme->getConfig();

        $viewParams = [
            'email'    => $email,
            'lead'     => $lead,
            'template' => $template,
            'message'  => $message,
        ];

        if (!empty($formContent)) {
            $viewParams['content'] = $formContent;
            if (in_array('form', $config['features'])) {
                $contentTemplate = $this->factory->getHelper('theme')->checkForTwigTemplate(':'.$template.':form.html.php');
            } else {
                $contentTemplate = 'MauticFormBundle::form.html.php';
            }
        }
        $viewParams['name']              = 'Unsubscribed';
        $viewParams['actionName']        = 'unsubscribe';
        $viewParams['subscriptiontitle'] = 'le.email.unsubscribe.title';
        $contentTemplate                 = 'MauticEmailBundle:Email:unsubscribe.html.php';

        return $this->render($contentTemplate, $viewParams);
    }

    /**
     * @param $idHash
     *
     * @return Response
     *
     * @throws \Exception
     * @throws \Mautic\CoreBundle\Exception\FileNotFoundException
     */
    public function resubscribeAction($idHash)
    {
        //find the email
        $model = $this->getModel('email');
        $stat  = $model->getEmailStatus($idHash);
        /** @var \Mautic\EmailBundle\Entity\StatRepository $statRepo */
        $statRepo = $model->getStatRepository();
        if (!empty($stat)) {
            $email = $stat->getEmail();
            $lead  = $stat->getLead();

            if ($lead) {
                // Set the lead as current lead
                /** @var \Mautic\LeadBundle\Model\LeadModel $leadModel */
                $leadModel = $this->getModel('lead');
                $lead->setStatus(1); //Active
                $leadModel->saveEntity($lead);
                $leadModel->setCurrentLead($lead);
                // Set lead lang
                if ($lead->getPreferredLocale()) {
                    $this->translator->setLocale($lead->getPreferredLocale());
                }
            }

            $model->removeDoNotContact($stat);

//            $message = $this->coreParametersHelper->getParameter('resubscribe_message');
            $message = false;
            if (!$message) {
                $message = $this->translator->trans(
                    'le.email.resubscribed.success',
                    [
                        '%unsubscribeUrl%' => '|URL|',
                        '%email%'          => '|EMAIL|',
                    ]
                );
            }
            $message = str_replace(
                [
                    '|URL|',
                    '|EMAIL|',
                ],
                [
                    $this->generateUrl('le_email_subscribe', ['idHash' => $idHash]),
                    $stat->getEmailAddress(),
                ],
                $message
            );
            $stat->setIsUnsubscribe(false);
            $statRepo->saveEntity($stat);
        } else {
            $email   = $lead   = false;
            $message = $this->translator->trans('le.email.stat_record.not_found');
        }

        $template = ($email !== null && 'le_code_mode' !== $email->getTemplate()) ? $email->getTemplate() : $this->coreParametersHelper->getParameter('theme');
        $theme    = null;
        if ($template != null) {
            $theme = $this->factory->getTheme($template);
        }

        if ($theme != null && $theme->getTheme() != $template) {
            $template = $theme->getTheme();
        }

        // Ensure template still exists
        if ($template != null) {
            $theme = $this->factory->getTheme($template);
            if (empty($theme) || $theme->getTheme() !== $template) {
                $template = $this->coreParametersHelper->getParameter('theme');
            }
        }

        $analytics = $this->factory->getHelper('template.analytics')->getCode();

        if (!empty($analytics)) {
            $this->factory->getHelper('template.assets')->addCustomDeclaration($analytics);
        }

        $logicalName = $this->factory->getHelper('theme')->checkForTwigTemplate(':'.$template.':message.html.php');
        $viewParams  = [
            'message'  => $message,
            'type'     => 'notice',
            'email'    => $email,
            'lead'     => $lead,
            'template' => $template,
        ];
        /*return $this->render(
            $logicalName,
            [
                'message'  => $message,
                'type'     => 'notice',
                'email'    => $email,
                'lead'     => $lead,
                'template' => $template,
            ]
        );*/
        $viewParams['name']              = 'Subscrptions';
        $viewParams['actionName']        = 'resubscribe';
        $viewParams['subscriptiontitle'] = 'le.email.resubscribe.title';
        $contentTemplate                 = 'MauticEmailBundle:Email:unsubscribe.html.php';

        return $this->render($contentTemplate, $viewParams);
    }

    /**
     * Handles mailer transport webhook post.
     *
     * @param $transport
     *
     * @return Response
     */
    public function mailerCallbackAction($transport)
    {
        ignore_user_abort(true);

        // Use the real transport as the one in Mailer could be SpoolTransport if the system is configured to queue
        // Can't use swiftmailer.transport.real because it's not set for when queue is disabled
        $transportParam   = $this->get('mautic.helper.core_parameters')->getParameter(('mailer_transport'));
        $currentTransport = $this->get('swiftmailer.mailer.transport.'.$transportParam);

        $isCallbackInterface = $currentTransport instanceof InterfaceCallbackTransport || $currentTransport instanceof CallbackTransportInterface;
        if ($isCallbackInterface && $currentTransport->getCallbackPath() == $transport) {
            // @deprecated support to be removed in 3.0
            if ($currentTransport instanceof InterfaceCallbackTransport) {
                $response = $currentTransport->handleCallbackResponse($this->request, $this->factory);

                if (is_array($response)) {
                    /** @var \Mautic\EmailBundle\Model\EmailModel $model */
                    $model = $this->getModel('email');

                    $model->processMailerCallback($response);
                }
            } elseif ($currentTransport instanceof CallbackTransportInterface) {
                $currentTransport->processCallbackRequest($this->request);
            }

            return new Response('success');
        }

        return $this->notFound();
    }

    /**
     * Handles bee free credentials request.
     *
     * @return JsonResponse
     */
    public function getBeeFreeCredentialsAction()
    {
        $clientid       = $this->get('mautic.helper.core_parameters')->getParameter('beeeditor_client_id');
        $clientsecret   = $this->get('mautic.helper.core_parameters')->getParameter('beeeditor_client_secret');
        $beefree        = new BeeFree($clientid, $clientsecret);
        $result         = $beefree->getCredentials();

        return new JsonResponse($result);
    }

    /**
     * Preview email.
     *
     * @param $objectId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function previewAction($objectId)
    {
        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model       = $this->getModel('email');
        $emailEntity = $model->getEntity($objectId);
        if ($emailEntity === null) {
            return $this->notFound();
        }

        if (
            ($this->get('mautic.security')->isAnonymous() && !$emailEntity->isPublished())
            || (!$this->get('mautic.security')->isAnonymous()
                && !$this->get('mautic.security')->hasEntityAccess(
                    'email:emails:viewown',
                    'email:emails:viewother',
                    $emailEntity->getCreatedBy()
                ))
        ) {
            return $this->accessDenied();
        }

        //bogus ID
        $idHash = 'xxxxxxxxxxxxxx';

        $BCcontent                = $emailEntity->getContent();
        $title                    =$emailEntity->getName();
        $content                  = $emailEntity->getCustomHtml();

        $emailEntity->setCustomHtml($content);

        $model->saveEntity($emailEntity);
        // if($content != null) {
        if (empty($content)) {
            $content ='No Preview Available...!';
        }
        $doc                      = new \DOMDocument();
        $doc->strictErrorChecking = false;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$content);
        // Get body tag.
        $body       = $doc->getElementsByTagName('body');
        $head       = $doc->getElementsByTagName('head');
        $mailhelper = $this->get('mautic.helper.mailer');
        $content    = $mailhelper->alterEmailBodyContent($content, $emailEntity->getEmailType());

        if ($head->length == 0) {
            $content = $this->get('mautic.helper.mailer')->appendHeadTag($content);
        }
        $content = $this->get('mautic.helper.mailer')->replaceTitleinContent($title, $content);
        $content = $this->get('mautic.helper.mailer')->replaceLinkinContent($content);

        if (empty($content) && !empty($BCcontent)) {
            $template = $emailEntity->getTemplate();
            $slots    = $this->factory->getTheme($template)->getSlots('email');

            $assetsHelper = $this->factory->getHelper('template.assets');

            $assetsHelper->addCustomDeclaration('<meta name="robots" content="noindex">');

            $this->processSlots($slots, $emailEntity);

            $logicalName = $this->factory->getHelper('theme')->checkForTwigTemplate(':'.$template.':email.html.php');

            $response = $this->render(
                $logicalName,
                [
                    'inBrowser' => true,
                    'slots'     => $slots,
                    'content'   => $emailEntity->getContent(),
                    'email'     => $emailEntity,
                    'lead'      => null,
                    'template'  => $template,
                ]
            );

            //replace tokens
            $content = $response->getContent();
        }

        // Convert emojis
        $content = EmojiHelper::toEmoji($content, 'short');

        // Override tracking_pixel
        $tokens = ['{tracking_pixel}' => ''];

        // Prepare a fake lead
        /** @var \Mautic\LeadBundle\Model\FieldModel $fieldModel */
        $fieldModel = $this->getModel('lead.field');
        $fields     = $fieldModel->getFieldList(false, false);
        array_walk(
            $fields,
            function (&$field) {
                $field = "[$field]";
            }
        );
        $fields['id'] = 0;

        // Generate and replace tokens
        $event = new EmailSendEvent(
            null,
            [
                'content'      => $content,
                'email'        => $emailEntity,
                'idHash'       => $idHash,
                'tokens'       => $tokens,
                'internalSend' => true,
                'lead'         => $fields,
            ]
        );
        $this->dispatcher->dispatch(EmailEvents::EMAIL_ON_DISPLAY, $event);

        $content = $event->getContent(true);
        /* } else {
             $content = 'No Preview Available...!';
         }*/
        return new Response($content);
    }

    /**
     * @param $slots
     * @param Email $entity
     */
    public function processSlots($slots, $entity)
    {
        /** @var \Mautic\CoreBundle\Templating\Helper\SlotsHelper $slotsHelper */
        $slotsHelper = $this->factory->getHelper('template.slots');

        $content = $entity->getContent();

        foreach ($slots as $slot => $slotConfig) {
            if (is_numeric($slot)) {
                $slot       = $slotConfig;
                $slotConfig = [];
            }

            $value = isset($content[$slot]) ? $content[$slot] : '';
            $slotsHelper->set($slot, $value);
        }
    }

    /**
     * @param $integration
     *
     * @throws \Exception
     */
    private function doTracking($integration)
    {
        $logger = $this->get('monolog.logger.mautic');

        // if additional data were sent with the tracking pixel
        $query_string = $this->request->server->get('QUERY_STRING');
        if (!$query_string) {
            $logger->log('error', $integration.': query string is not available');

            return;
        }

        if (strpos($query_string, 'r=') === 0) {
            $query_string = substr($query_string, strpos($query_string, '?') + 1);
        } // remove route variable

        parse_str($query_string, $query);

        // URL attr 'd' is encoded so let's decode it first.
        if (!isset($query['d'], $query['sig'])) {
            $logger->log('error', $integration.': query variables are not found');

            return;
        }

        // get secret from plugin settings
        $integrationHelper = $this->get('mautic.helper.integration');
        $myIntegration     = $integrationHelper->getIntegrationObject($integration);

        if (!$myIntegration) {
            $logger->log('error', $integration.': integration not found');

            return;
        }
        $keys = $myIntegration->getDecryptedApiKeys();

        // generate signature
        $salt = $keys['secret'];
        if (strpos($salt, '$1$') === false) {
            $salt = '$1$'.$salt;
        } // add MD5 prefix
        $cr    = crypt(urlencode($query['d']), $salt);
        $mySig = hash('crc32b', $cr); // this hash type is used in c#

        // compare signatures
        if (hash_equals($mySig, $query['sig'])) {
            // decode and parse query variables
            $b64 = base64_decode($query['d']);
            $gz  = gzdecode($b64);
            parse_str($gz, $query);
        } else {
            // signatures don't match: stop
            $logger->log('error', $integration.': signatures don\'t match');

            unset($query);
        }

        if (empty($query) || !isset($query['email'], $query['subject'], $query['body'])) {
            $logger->log('error', $integration.': query variables are empty');

            return;
        }

        if (MAUTIC_ENV === 'dev') {
            $logger->log('error', $integration.': '.json_encode($query, JSON_PRETTY_PRINT));
        }

        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');

        // email is a semicolon delimited list of emails
        $emails = explode(';', $query['email']);
        $repo   = $this->getModel('lead')->getRepository();

        foreach ($emails as $email) {
            $lead = $repo->getLeadByEmail($email);
            if ($lead === null) {
                $lead = $this->createLead($email, $repo);
            }

            if ($lead === null) {
                continue;
            } // lead was not created

            $idHash = hash('crc32', $email.$query['body']);
            $idHash = substr($idHash.$idHash, 0, 13); // 13 bytes length

            $stat = $model->getEmailStatus($idHash);

            // stat doesn't exist, create one
            if ($stat === null) {
                $lead['email'] = $email; // needed for stat
                $stat          = $this->addStat($lead, $email, $query, $idHash);
            }

            $stat->setSource('email.client');

            if ($stat || $integration !== 'Outlook') { // Outlook requests the tracking gif on send
                $model->hitEmail($idHash, $this->request); // add email event
            }
        }
    }

    /**
     * @param $integration
     *
     * @return Response
     */
    public function pluginTrackingGifAction($integration)
    {
        $this->doTracking($integration);

        return TrackingPixelHelper::getResponse($this->request); // send gif
    }

    /**
     * @param $lead
     * @param $email
     * @param $query
     * @param $idHash
     */
    private function addStat($lead, $email, $query, $idHash)
    {
        if ($lead !== null) {
            /** @var \Mautic\EmailBundle\Helper\MailHelper $mailer */
            $mailer = $this->get('mautic.helper.mailer');

            // To lead
            $mailer->addTo($email);

            // sanitize variables to prevent malicious content
            $from = filter_var($query['from'], FILTER_SANITIZE_EMAIL);
            $mailer->setFrom($from, '');

            // Set Content
            $body = filter_var($query['body'], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
            $mailer->setBody($body);
            $mailer->parsePlainText($body);

            // Set lead
            $mailer->setLead($lead);
            $mailer->setIdHash($idHash);

            $subject = filter_var($query['subject'], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
            $mailer->setSubject($subject);

            return $mailer->createEmailStat();
        }

        return null;
    }

    /**
     * @param $email
     * @param $repo
     *
     * @return mixed
     */
    private function createLead($email, $repo)
    {
        $model = $this->getModel('lead.lead');
        $lead  = $model->getEntity();
        // set custom field values
        $data = ['email' => $email];
        $model->setFieldValues($lead, $data, true);
        // create lead
        $model->saveEntity($lead);

        // return entity
        return $repo->getLeadByEmail($email);
    }

    /**
     * @param $idHash
     * @param $model
     * @param $stat
     * @param $translator
     *
     * @return mixed
     */
    public function getUnsubscribeMessage($idHash, $model, Stat $stat, $translator)
    {
        if (!$stat->getIsUnsubscribe()) {
            $model->setDoNotContact($stat, $translator->trans('le.email.dnc.unsubscribed'), DoNotContact::UNSUBSCRIBED);
        }

        //$message = $this->coreParametersHelper->getParameter('unsubscribe_message');
        $message = false;
        if (!$message) {
            $message = $translator->trans(
                'le.email.unsubscribed.success',
                [
                    '%resubscribeUrl%' => '|URL|',
                    '%email%'          => '|EMAIL|',
                ]
            );
        }
        $message = str_replace(
            [
                '|URL|',
                '|EMAIL|',
            ],
            [
                $this->generateUrl('le_email_resubscribe', ['idHash' => $idHash]),
                $stat->getEmailAddress(),
            ],
            $message
        );

        return $message;
    }

    /**
     * @param $idhash
     *
     * @return Response
     */
    public function verifySenderProfileAction($idhash)
    {
        $emailmodel = $this->getModel('email');
        $response   =$emailmodel->verifySenderProfile($idhash);
        if ($response['status']) {
            $this->addFlash('le.config.sender.email.verification.success', ['%sender%'=> $response['email']]);

            return $this->delegateRedirect($this->generateUrl('le_config_action', ['objectAction' => 'edit']));
        } else {
            return new Response("<html><body><p style='font-size: 20px;'>".$this->translator->trans('le.config.sender.email.verification.failure').'</p></body></html>');
        }
    }
}
