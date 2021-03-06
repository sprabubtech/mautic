<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\FormBundle\Model;

use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Exception\FileUploadException;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Helper\LicenseInfoHelper;
use Mautic\CoreBundle\Helper\TemplatingHelper;
use Mautic\CoreBundle\Model\FormModel as CommonFormModel;
use Mautic\FormBundle\Crate\UploadFileCrate;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\Event\ValidationEvent;
use Mautic\FormBundle\Exception\FileValidationException;
use Mautic\FormBundle\Exception\NoFileGivenException;
use Mautic\FormBundle\Exception\ValidationException;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Mautic\FormBundle\Helper\FormUploader;
use Mautic\FormBundle\Validator\UploadFieldValidator;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\CompanyChangeLog;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel as LeadFieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\Service\DeviceTrackingService\DeviceTrackingServiceInterface;
use Mautic\PageBundle\Model\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class SubmissionModel.
 */
class SubmissionModel extends CommonFormModel
{
    /**
     * @deprecated Remove in 2.0
     *
     * @var MauticFactory
     */
    protected $factory;

    /**
     * @var IpLookupHelper
     */
    protected $ipLookupHelper;

    /**
     * @var TemplatingHelper
     */
    protected $templatingHelper;

    /**
     * @var FormModel
     */
    protected $formModel;

    /**
     * @var PageModel
     */
    protected $pageModel;

    /**
     * @var LeadModel
     */
    protected $leadModel;

    /**
     * @var CampaignModel
     */
    protected $campaignModel;

    /**
     * @var LeadFieldModel
     */
    protected $leadFieldModel;

    /**
     * @var CompanyModel
     */
    protected $companyModel;

    /**
     * @var FormFieldHelper
     */
    protected $fieldHelper;

    /**
     * @var UploadFieldValidator
     */
    private $uploadFieldValidator;

    /**
     * @var FormUploader
     */
    private $formUploader;

    /** @var DeviceTrackingServiceInterface */
    private $deviceTrackingService;

    /**
     * @var LicenseInfoHelper
     */
    private $licenseInfoHelper;

    /**
     * @param IpLookupHelper                 $ipLookupHelper
     * @param TemplatingHelper               $templatingHelper
     * @param FormModel                      $formModel
     * @param PageModel                      $pageModel
     * @param LeadModel                      $leadModel
     * @param CampaignModel                  $campaignModel
     * @param LeadFieldModel                 $leadFieldModel
     * @param CompanyModel                   $companyModel
     * @param FormFieldHelper                $fieldHelper
     * @param UploadFieldValidator           $uploadFieldValidator
     * @param FormUploader                   $formUploader
     * @param LicenseInfoHelper              $licenseInfoHelper
     * @param DeviceTrackingServiceInterface $deviceTrackingService
     */
    public function __construct(
        IpLookupHelper $ipLookupHelper,
        TemplatingHelper $templatingHelper,
        FormModel $formModel,
        PageModel $pageModel,
        LeadModel $leadModel,
        CampaignModel $campaignModel,
        LeadFieldModel $leadFieldModel,
        CompanyModel $companyModel,
        FormFieldHelper $fieldHelper,
        UploadFieldValidator $uploadFieldValidator,
        FormUploader $formUploader,
        LicenseInfoHelper $licenseInfoHelper,
        DeviceTrackingServiceInterface $deviceTrackingService
    ) {
        $this->ipLookupHelper         = $ipLookupHelper;
        $this->templatingHelper       = $templatingHelper;
        $this->formModel              = $formModel;
        $this->pageModel              = $pageModel;
        $this->leadModel              = $leadModel;
        $this->campaignModel          = $campaignModel;
        $this->leadFieldModel         = $leadFieldModel;
        $this->companyModel           = $companyModel;
        $this->fieldHelper            = $fieldHelper;
        $this->uploadFieldValidator   = $uploadFieldValidator;
        $this->formUploader           = $formUploader;
        $this->deviceTrackingService  = $deviceTrackingService;
        $this->licenseInfoHelper      = $licenseInfoHelper;
    }

    /**
     * {@inheritdoc}
     *
     * @return \Mautic\FormBundle\Entity\SubmissionRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticFormBundle:Submission');
    }

    /**
     * @param              $post
     * @param              $server
     * @param Form         $form
     * @param Request|null $request
     * @param bool         $returnEvent
     *
     * @return bool|array
     */
    public function saveSubmission($post, $server, Form $form, Request $request = null, $returnEvent = false)
    {
        $leadFields = $this->leadFieldModel->getFieldListWithProperties(false);

        $isValidRecordAdd = $this->licenseInfoHelper->isValidRecordAdd();

        //everything matches up so let's save the results
        $submission = new Submission();
        $submission->setDateSubmitted(new \DateTime());
        $submission->setForm($form);

        if (!isset($post['gdpr']) && $form->getGDPRPublished()) {
            $post['gdpr'] = ['Denied'];
        }

        //set the landing page the form was submitted from if applicable
        if (!empty($post['mauticpage'])) {
            $page = $this->pageModel->getEntity((int) $post['mauticpage']);
            if ($page != null) {
                $submission->setPage($page);
            }
        }

        $ipAddress = $this->ipLookupHelper->getIpAddress();
        $submission->setIpAddress($ipAddress);

        if (!empty($post['return'])) {
            $referer = $post['return'];
        } elseif (!empty($server['HTTP_REFERER'])) {
            $referer = $server['HTTP_REFERER'];
        } else {
            $referer = '';
        }

        //clean the referer by removing mauticError and mauticMessage
        $referer = InputHelper::url($referer, null, null, ['leError', 'leMessage']);
        $submission->setReferer($referer);

        // Create an event to be dispatched through the processes
        $submissionEvent = new SubmissionEvent($submission, $post, $server, $request);

        // Get a list of components to build custom fields from
        $components = $this->formModel->getCustomComponents();

        $fields           = $form->isSmartForm() ? $this->getSmartFormFields($form) : $form->getFields();
        $fieldArray       = [];
        $results          = [];
        $tokens           = [];
        $leadFieldMatches = [];
        $validationErrors = [];
        $filesToUpload    = new UploadFileCrate();

        /** @var Field $f */
        foreach ($fields as $f) {
            $id              = $f->getId();
            $type            = $f->getType();
            $alias           = $f->getAlias();
            $dbalias         = $f->getDbField();
            $value           = (isset($post[$dbalias]) || isset($post[$alias])) ? $form->isSmartForm() ? $post[$dbalias] : $post[$alias] : '';
            $fieldArray[$id] = [
                'id'    => $id,
                'type'  => $type,
                'alias' => $alias,
            ];

            if ($f->isCaptchaType()) {
                $captcha = $this->fieldHelper->validateFieldValue($type, $value, $f);
                if (!empty($captcha)) {
                    $props = $f->getProperties();
                    //check for a custom message
                    $validationErrors[$alias] = (!empty($props['errorMessage'])) ? $props['errorMessage'] : implode('<br />', $captcha);
                }
                continue;
            }
            if ($f->isGCaptchaType()) {
                $value=isset($post['g-recaptcha-response']) ? $post['g-recaptcha-response'] : '';
                if (empty($value) || !$this->verifyGoogleCaptchaResponse($value)) {
                    $validationErrors[$alias] =$f->getValidationMessage();
                }
                continue;
            } elseif ($f->isFileType()) {
                try {
                    $file  = $this->uploadFieldValidator->processFileValidation($f, $request);
                    $value = $file->getClientOriginalName();
                    $filesToUpload->addFile($file, $f);
                } catch (NoFileGivenException $e) { //No error here, we just move to another validation, eg. if a field is required
                } catch (FileValidationException $e) {
                    $validationErrors[$alias] = $e->getMessage();
                }
            }

            if ($value === '' && $f->isRequired()) {
                //field is required, but hidden from form because of 'ShowWhenValueExists'
                if ($f->getShowWhenValueExists() === false && !isset($post[$alias])) {
                    continue;
                }

                //somehow the user got passed the JS validation
                $msg = $f->getValidationMessage();
                if (empty($msg)) {
                    $msg = $this->translator->trans(
                        'mautic.form.field.generic.validationfailed',
                        [
                            '%label%' => $f->getLabel(),
                        ],
                        'validators'
                    );
                }

                $validationErrors[$alias] = $msg;

                continue;
            }

            if (isset($components['viewOnlyFields']) && in_array($type, $components['viewOnlyFields'])) {
                //don't save items that don't have a value associated with it
                continue;
            }

            //clean and validate the input
            if ($f->isCustom()) {
                if (!isset($components['fields'][$f->getType()])) {
                    continue;
                }

                $params = $components['fields'][$f->getType()];
                if (!empty($value)) {
                    if (isset($params['valueFilter'])) {
                        if (is_string($params['valueFilter']) && is_callable(['\Mautic\CoreBundle\Helper\InputHelper', $params['valueFilter']])) {
                            $value = InputHelper::_($value, $params['valueFilter']);
                        } elseif (is_callable($params['valueFilter'])) {
                            $value = call_user_func_array($params['valueFilter'], [$f, $value]);
                        } else {
                            $value = InputHelper::_($value, 'clean');
                        }
                    } else {
                        $value = InputHelper::_($value, 'clean');
                    }
                }

                // @deprecated - BC support; to be removed in 3.0 - be sure to remove support in FormBuilderEvent as well
                if (isset($params['valueConstraints']) && is_callable($params['valueConstraints'])) {
                    $customErrors = call_user_func_array($params['valueConstraints'], [$f, $value]);
                    if (!empty($customErrors)) {
                        $validationErrors[$alias] = is_array($customErrors) ? implode('<br />', $customErrors) : $customErrors;
                    }
                }
            } elseif (!empty($value)) {
                $filter = $this->fieldHelper->getFieldFilter($type);
                $value  = InputHelper::_($value, $filter);

                $isValid = $this->validateFieldValue($f, $value);
                if (true !== $isValid) {
                    $validationErrors[$alias] = is_array($isValid) ? implode('<br />', $isValid) : $isValid;
                }
            }

            // Check for custom validators
            $isValid = $this->validateFieldValue($f, $value);
            if (true !== $isValid) {
                $validationErrors[$alias] = $isValid;
            }

            $leadField = $f->getLeadField();
            if (!empty($leadField)) {
                $leadValue = $value;

                $leadFieldMatches[$leadField] = $leadValue;
            }

            //convert array from checkbox groups and multiple selects
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $tokens["{formfield={$alias}}"] = $value;

            //save the result
            if ($f->getSaveResult() !== false) {
                $results[$alias] = $value;
            }
        }

        // Set the results
        $submission->setResults($results);

        // Update the event
        $submissionEvent->setFields($fieldArray)
            ->setTokens($tokens)
            ->setResults($results)
            ->setContactFieldMatches($leadFieldMatches);

        // @deprecated - BC support; to be removed in 3.0 - be sure to remove the validator option from addSubmitAction as well
        $this->validateActionCallbacks($submissionEvent, $validationErrors, $alias);

        // Create/update lead
        $lead = null;
        if (!empty($leadFieldMatches)) {
            if ($isValidRecordAdd) {
                $lead = $this->createLeadFromSubmit($form, $leadFieldMatches, $leadFields);
                $this->licenseInfoHelper->intRecordCount('1', true);
            } else {
                $actualrecord = $this->licenseInfoHelper->getActualRecordCount();
                $totalrecord  = $this->licenseInfoHelper->getTotalRecordCount();
                $actualrecord = number_format($actualrecord);
                $totalrecord  = $totalrecord == 'UL' ? 'Unlimited' : number_format($totalrecord);
                $msg          = $this->translator->trans('le.record.count.exceeds', ['%USEDCOUNT%' => $actualrecord, '%ACTUALCOUNT%' => $totalrecord]);

                return ['errors' => 'Insufficient Record Count Please Contact Support Team'];
            }
        }

        // Get updated lead if applicable with tracking ID
        /** @var Lead $lead */
        if ($lead == null) {
            $lead = $this->leadModel->getCurrentLead();
        }
        $trackedDevice = $this->deviceTrackingService->getTrackedDevice();
        $trackingId    = ($trackedDevice === null ? null : $trackedDevice->getTrackingId());
        //set tracking ID for stats purposes to determine unique hits
        $submission->setTrackingId($trackingId)
            ->setLead($lead);

        // Remove validation errors if the field is not visible
        if ($lead && $form->usesProgressiveProfiling()) {
            $leadSubmissions = $this->formModel->getLeadSubmissions($form, $lead->getId());

            foreach ($fields as $field) {
                if (isset($validationErrors[$field->getAlias()]) && !$field->showForContact($leadSubmissions, $lead, $form)) {
                    unset($validationErrors[$field->getAlias()]);
                }
            }
        }

        //return errors if there any
        if (!empty($validationErrors)) {
            return ['errors' => $validationErrors];
        }

        /*
         * Process File upload and save the result to the entity
         * Upload is here to minimize a need for deleting file if there is a validation error
         * The action can still be invalidated below - deleteEntity takes care for File deletion
         *
         * @todo Refactor form validation to execute this code only if Submission is valid
         */
        try {
            $this->formUploader->uploadFiles($filesToUpload, $submission);
        } catch (FileUploadException $e) {
            $msg                                = $this->translator->trans('mautic.form.submission.error.file.uploadFailed', [], 'validators');
            $validationErrors[$e->getMessage()] = $msg;

            return ['errors' => $validationErrors];
        }

        // Save the submission
        $this->saveEntity($submission);

        // Now handle post submission actions
        try {
            if ($lead != null) {
                $submissionEvent->setLead($lead);
                $this->executeFormActions($submissionEvent, $lead);
            }
        } catch (ValidationException $exception) {
            // The action invalidated the form for whatever reason
            $this->deleteEntity($submission);

            if ($validationErrors = $exception->getViolations()) {
                return ['errors' => $validationErrors];
            }

            return ['errors' => [$exception->getMessage()]];
        }
        //if (!$form->isStandalone()) {
        // Find and add the lead to the associated campaigns
        $formId    = ($form instanceof Form) ? $form->getId() : $form;
        if (!$this->factory->get('le.helper.statemachine')->isAnyInActiveStateAlive()) {
            $campaigns = $this->campaignModel->getRepository()->getPublishedCampaignbySourceType('forms');
            if (!empty($campaigns)) {
                foreach ($campaigns as $c) {
                    foreach ($c as $event) {
                        $properties = unserialize($event['properties']);
                        if (in_array($formId, $properties['forms'])) {
                            $campaign = $this->em->getReference('MauticCampaignBundle:Campaign', $event['id']);
                            if ($event['goal'] != 'interrupt') {
                                $this->campaignModel->addLead($campaign, $lead);
                                $this->campaignModel->putCampaignEventLog($event['eventid'], $campaign, $lead);
                            } else {
                                $this->campaignModel->checkGoalAchievedByLead($campaign, $lead, $event['eventid']);
                            }
                            unset($campaign);
                        }
                    }
                }
            }
        }
        //  }

        if ($this->dispatcher->hasListeners(FormEvents::FORM_ON_SUBMIT)) {
            // Reset action config from executeFormActions()
            $submissionEvent->setActionConfig(null, []);

            // Dispatch to on submit listeners
            $this->dispatcher->dispatch(FormEvents::FORM_ON_SUBMIT, $submissionEvent);
        }

        //get callback commands from the submit action
        if ($submissionEvent->hasPostSubmitCallbacks()) {
            return ['callback' => $submissionEvent];
        }

        // made it to the end so return the submission event to give the calling method access to tokens, results, etc
        // otherwise return false that no errors were encountered (to keep BC really)
        return ($returnEvent) ? ['submission' => $submissionEvent] : false;
    }

    /**
     * @param Submission $submission
     */
    public function deleteEntity($submission)
    {
        $this->formUploader->deleteUploadedFiles($submission);

        parent::deleteEntity($submission);
    }

    /**
     * {@inheritdoc}
     */
    public function getEntities(array $args = [])
    {
        return $this->getRepository()->getEntities($args);
    }

    /**
     * @param $format
     * @param $form
     * @param $queryArgs
     *
     * @return StreamedResponse|Response
     *
     * @throws \Exception
     */
    public function exportResults($format, $form, $queryArgs)
    {
        $viewOnlyFields              = $this->formModel->getCustomComponents()['viewOnlyFields'];
        $queryArgs['viewOnlyFields'] = $viewOnlyFields;
        $queryArgs['simpleResults']  = true;
        $results                     = $this->getEntities($queryArgs);
        $translator                  = $this->translator;

        $date = (new DateTimeHelper())->toLocalString();
        $name = str_replace(' ', '_', $date).'_'.$form->getAlias();

        switch ($format) {
            case 'csv':
                $response = new StreamedResponse(
                    function () use ($results, $form, $translator, $viewOnlyFields) {
                        $handle = fopen('php://output', 'r+');

                        //build the header row
                        $fields = $form->getFields();
                        $header = [
                            $translator->trans('mautic.core.id'),
                            $translator->trans('mautic.form.result.thead.date'),
                            $translator->trans('mautic.core.ipaddress'),
                            $translator->trans('mautic.form.result.thead.referrer'),
                        ];
                        foreach ($fields as $f) {
                            if (in_array($f->getType(), $viewOnlyFields) || $f->getSaveResult() === false) {
                                continue;
                            }
                            $header[] = $f->getLabel();
                        }
                        //free memory
                        unset($fields);

                        //write the row
                        fputcsv($handle, $header);

                        //build the data rows
                        foreach ($results as $k => $s) {
                            $row = [
                                $s['id'],
                                $s['dateSubmitted'],
                                $s['ipAddress'],
                                $s['referer'],
                            ];
                            foreach ($s['results'] as $k2 => $r) {
                                if (in_array($r['type'], $viewOnlyFields)) {
                                    continue;
                                }
                                $row[] = htmlspecialchars_decode($r['value'], ENT_QUOTES);
                                //free memory
                                unset($s['results'][$k2]);
                            }

                            fputcsv($handle, $row);

                            //free memory
                            unset($row, $results[$k]);
                        }

                        fclose($handle);
                    }
                );

                $response->headers->set('Content-Type', 'application/force-download');
                $response->headers->set('Content-Type', 'application/octet-stream');
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$name.'.csv"');
                $response->headers->set('Expires', 0);
                $response->headers->set('Cache-Control', 'must-revalidate');
                $response->headers->set('Pragma', 'public');

                return $response;
            case 'html':
                $content = $this->templatingHelper->getTemplating()->renderResponse(
                    'MauticFormBundle:Result:export.html.php',
                    [
                        'form'           => $form,
                        'results'        => $results,
                        'pageTitle'      => $name,
                        'viewOnlyFields' => $viewOnlyFields,
                    ]
                )->getContent();

                return new Response($content);
            case 'xlsx':
                if (class_exists('PHPExcel')) {
                    $response = new StreamedResponse(
                        function () use ($results, $form, $translator, $name, $viewOnlyFields) {
                            $objPHPExcel = new \PHPExcel();
                            $objPHPExcel->getProperties()->setTitle($name);

                            $objPHPExcel->createSheet();

                            //build the header row
                            $fields = $form->getFields();
                            $header = [
                                $translator->trans('mautic.core.id'),
                                $translator->trans('mautic.form.result.thead.date'),
                                $translator->trans('mautic.core.ipaddress'),
                                $translator->trans('mautic.form.result.thead.referrer'),
                            ];
                            foreach ($fields as $f) {
                                if (in_array($f->getType(), $viewOnlyFields) || $f->getSaveResult() === false) {
                                    continue;
                                }
                                $header[] = $f->getLabel();
                            }
                            //free memory
                            unset($fields);

                            //write the row
                            $objPHPExcel->getActiveSheet()->fromArray($header, null, 'A1');

                            //build the data rows
                            $count = 2;
                            foreach ($results as $k => $s) {
                                $row = [
                                    $s['id'],
                                    $s['dateSubmitted'],
                                    $s['ipAddress'],
                                    $s['referer'],
                                ];
                                foreach ($s['results'] as $k2 => $r) {
                                    if (in_array($r['type'], $viewOnlyFields)) {
                                        continue;
                                    }
                                    $row[] = htmlspecialchars_decode($r['value'], ENT_QUOTES);
                                    //free memory
                                    unset($s['results'][$k2]);
                                }

                                $objPHPExcel->getActiveSheet()->fromArray($row, null, "A{$count}");

                                //free memory
                                unset($row, $results[$k]);

                                //increment letter
                                ++$count;
                            }

                            $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
                            $objWriter->setPreCalculateFormulas(false);

                            $objWriter->save('php://output');
                        }
                    );
                    $response->headers->set('Content-Type', 'application/force-download');
                    $response->headers->set('Content-Type', 'application/octet-stream');
                    $response->headers->set('Content-Disposition', 'attachment; filename="'.$name.'.xlsx"');
                    $response->headers->set('Expires', 0);
                    $response->headers->set('Cache-Control', 'must-revalidate');
                    $response->headers->set('Pragma', 'public');

                    return $response;
                }
                throw new \Exception('PHPExcel is required to export to Excel spreadsheets');
            default:
                return new Response();
        }
    }

    /**
     * Get line chart data of submissions.
     *
     * @param char      $unit          {@link php.net/manual/en/function.date.php#refsect1-function.date-parameters}
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param string    $dateFormat
     * @param array     $filter
     * @param bool      $canViewOthers
     *
     * @return array
     */
    public function getSubmissionsLineChartData(
        $unit,
        \DateTime $dateFrom,
        \DateTime $dateTo,
        $dateFormat = null,
        $filter = [],
        $canViewOthers = true
    ) {
        $chart = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo);
        $q     = $query->prepareTimeDataQuery('form_submissions', 'date_submitted', $filter);

        if (!$canViewOthers) {
            $q->join('t', MAUTIC_TABLE_PREFIX.'forms', 'f', 'f.id = t.form_id')
                ->andWhere('f.created_by = :userId')
                ->setParameter('userId', $this->userHelper->getUser()->getId());
        }

        $data = $query->loadAndBuildTimeData($q);
        $chart->setDataset($this->translator->trans('mautic.form.submission.count'), $data);

        return $chart->render();
    }

    /**
     * Get a list of top submission referrers.
     *
     * @param int    $limit
     * @param string $dateFrom
     * @param string $dateTo
     * @param array  $filters
     * @param bool   $canViewOthers
     *
     * @return array
     */
    public function getTopSubmissionReferrers($limit = 10, $dateFrom = null, $dateTo = null, $filters = [], $canViewOthers = true)
    {
        $q = $this->em->getConnection()->createQueryBuilder();
        $q->select('COUNT(DISTINCT t.id) AS submissions, t.referer')
            ->from(MAUTIC_TABLE_PREFIX.'form_submissions', 't')
            ->orderBy('submissions', 'DESC')
            ->groupBy('t.referer')
            ->setMaxResults($limit);

        if (!$canViewOthers) {
            $q->join('t', MAUTIC_TABLE_PREFIX.'forms', 'f', 'f.id = t.form_id')
                ->andWhere('f.created_by = :userId')
                ->setParameter('userId', $this->userHelper->getUser()->getId());
        }

        $chartQuery = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo);
        $chartQuery->applyFilters($q, $filters);
        $chartQuery->applyDateFilters($q, 'date_submitted');

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * Get a list of the most submisions per lead.
     *
     * @param int    $limit
     * @param string $dateFrom
     * @param string $dateTo
     * @param array  $filters
     * @param bool   $canViewOthers
     *
     * @return array
     */
    public function getTopSubmitters($limit = 10, $dateFrom = null, $dateTo = null, $filters = [], $canViewOthers = true)
    {
        $q = $this->em->getConnection()->createQueryBuilder();
        $q->select('COUNT(DISTINCT t.id) AS submissions, t.lead_id, l.firstname, l.lastname, l.email')
            ->from(MAUTIC_TABLE_PREFIX.'form_submissions', 't')
            ->join('t', MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.id = t.lead_id')
            ->orderBy('submissions', 'DESC')
            ->groupBy('t.lead_id, l.firstname, l.lastname, l.email')
            ->setMaxResults($limit);

        if (!$canViewOthers) {
            $q->join('t', MAUTIC_TABLE_PREFIX.'forms', 'f', 'f.id = t.form_id')
                ->andWhere('f.created_by = :userId')
                ->setParameter('userId', $this->userHelper->getUser()->getId());
        }

        $chartQuery = new ChartQuery($this->em->getConnection(), $dateFrom, $dateTo);
        $chartQuery->applyFilters($q, $filters);
        $chartQuery->applyDateFilters($q, 'date_submitted');

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * Execute a form submit action.
     *
     * @param SubmissionEvent $event
     * @param Lead            $lead
     *
     * @throws ValidationException
     */
    protected function executeFormActions(SubmissionEvent $event, Lead $lead)
    {
        $actions          = $event->getSubmission()->getForm()->getActions();
        $availableActions = $this->formModel->getCustomComponents()['actions'];

        // @deprecated support for callback - to be removed in 3.0
        $args = [
            'post'       => $event->getPost(),
            'server'     => $event->getServer(),
            'factory'    => $this->factory, // WHAT??
            'submission' => $event->getSubmission(),
            'fields'     => $event->getFields(),
            'form'       => $event->getSubmission()->getForm(),
            'tokens'     => $event->getTokens(),
            'feedback'   => [],
            'lead'       => $event->getSubmission()->getLead(),
        ];

        foreach ($actions as $action) {
            $key = $action->getType();
            if (!isset($availableActions[$key])) {
                continue;
            }

            $settings = $availableActions[$key];
            if (isset($settings['eventName'])) {
                $event->setActionConfig($key, $action->getProperties());
                $this->dispatcher->dispatch($settings['eventName'], $event);

                // @deprecated support for callback - to be removed in 3.0
                $args['lead']     = $event->getSubmission()->getLead();
                $args['feedback'] = $event->getActionFeedback();
            } elseif (isset($settings['callback'])) {
                // @deprecated support for callback - to be removed in 3.0; be sure to remove callback support from FormBuilderEvent as well

                $args['action'] = $action;
                $args['config'] = $action->getProperties();

                // Set the lead each time in case an action updates it
                $args['lead'] = ($lead == null) ? $this->leadModel->getCurrentLead() : $lead;

                $callback = $settings['callback'];
                if (is_callable($callback)) {
                    if (is_array($callback)) {
                        $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                    } elseif (strpos($callback, '::') !== false) {
                        $parts      = explode('::', $callback);
                        $reflection = new \ReflectionMethod($parts[0], $parts[1]);
                    } else {
                        $reflection = new \ReflectionMethod(null, $callback);
                    }

                    $pass = [];
                    foreach ($reflection->getParameters() as $param) {
                        if (isset($args[$param->getName()])) {
                            $pass[] = $args[$param->getName()];
                        } else {
                            $pass[] = null;
                        }
                    }
                    $returned               = $reflection->invokeArgs($this, $pass);
                    $args['feedback'][$key] = $returned;

                    // Set these for updated plugins to leverage
                    if (isset($returned['callback'])) {
                        $event->setPostSubmitCallback($key, $returned);
                    }

                    $event->setActionFeedback($key, $returned);
                }
            }
        }
    }

    /**
     * Create/update lead from form submit.
     *
     * @param Form  $form
     * @param array $leadFieldMatches
     *
     * @return Lead
     */
    protected function createLeadFromSubmit(Form $form, array $leadFieldMatches, $leadFields)
    {
        //set the mapped data
        $inKioskMode   = $form->isInKioskMode();
        $leadId        = null;
        $lead          = new Lead();
        $currentFields = $leadFieldMatches;
        $companyFields = $this->leadFieldModel->getFieldListWithProperties('company');

        if (!$inKioskMode) {
            // Default to currently tracked lead
            if ($currentLead = $this->leadModel->getCurrentLead()) {
                $lead          = $currentLead;
                $leadId        = $lead->getId();
                $currentFields = $lead->getProfileFields();
            }

            $this->logger->debug('FORM: Not in kiosk mode so using current contact ID #'.$leadId);
        } else {
            // Default to a new lead in kiosk mode
            $lead->setNewlyCreated(true);

            $this->logger->debug('FORM: In kiosk mode so assuming a new contact');
        }

        $uniqueLeadFields = $this->leadFieldModel->getUniqueIdentifierFields();

        // Closure to get data and unique fields
        $getData = function ($currentFields, $uniqueOnly = false) use ($leadFields, $uniqueLeadFields) {
            $uniqueFieldsWithData = $data = [];
            foreach ($leadFields as $alias => $properties) {
                if (isset($currentFields[$alias])) {
                    $value        = $currentFields[$alias];
                    $data[$alias] = $value;

                    // make sure the value is actually there and the field is one of our uniques
                    if (!empty($value) && array_key_exists($alias, $uniqueLeadFields)) {
                        $uniqueFieldsWithData[$alias] = $value;
                    }
                }
            }

            return ($uniqueOnly) ? $uniqueFieldsWithData : [$data, $uniqueFieldsWithData];
        };

        // Closure to get data and unique fields
        $getCompanyData = function ($currentFields) use ($companyFields) {
            $companyData = [];
            foreach ($companyFields as $alias => $properties) {
                if (isset($currentFields[$alias])) {
                    $value               = $currentFields[$alias];
                    $companyData[$alias] = $value;
                }
            }

            return $companyData;
        };

        // Closure to help search for a conflict
        $checkForIdentifierConflict = function ($fieldSet1, $fieldSet2) {
            // Find fields in both sets
            $potentialConflicts = array_keys(
                array_intersect_key($fieldSet1, $fieldSet2)
            );

            $this->logger->debug(
                'FORM: Potential conflicts '.implode(', ', array_keys($potentialConflicts)).' = '.implode(', ', $potentialConflicts)
            );

            $conflicts = [];
            foreach ($potentialConflicts as $field) {
                if (!empty($fieldSet1[$field]) && !empty($fieldSet2[$field])) {
                    if (strtolower($fieldSet1[$field]) !== strtolower($fieldSet2[$field])) {
                        $conflicts[] = $field;
                    }
                }
            }

            return [count($conflicts), $conflicts];
        };

        // Get data for the form submission
        list($data, $uniqueFieldsWithData) = $getData($leadFieldMatches);
        $this->logger->debug('FORM: Unique fields submitted include '.implode(', ', $uniqueFieldsWithData));

        // Check for duplicate lead
        /** @var \Mautic\LeadBundle\Entity\Lead[] $leads */
        $leads = (!empty($uniqueFieldsWithData)) ? $this->em->getRepository('MauticLeadBundle:Lead')->getLeadsByUniqueFields(
            $uniqueFieldsWithData,
            $leadId
        ) : [];

        $uniqueFieldsCurrent = $getData($currentFields, true);
        if (count($leads)) {
            $this->logger->debug(count($leads).' found based on unique identifiers');

            /** @var \Mautic\LeadBundle\Entity\Lead $foundLead */
            $foundLead = $leads[0];

            $this->logger->debug('FORM: Testing contact ID# '.$foundLead->getId().' for conflicts');

            // Check for a conflict with the currently tracked lead
            $foundLeadFields = $this->leadModel->flattenFields($foundLead->getFields());

            // Get unique identifier fields for the found lead then compare with the lead currently tracked
            $uniqueFieldsFound             = $getData($foundLeadFields, true);
            list($hasConflict, $conflicts) = $checkForIdentifierConflict($uniqueFieldsFound, $uniqueFieldsCurrent);

            if ($inKioskMode || $hasConflict || !$lead->getId()) {
                // Use the found lead without merging because there is some sort of conflict with unique identifiers or in kiosk mode and thus should not merge
                $lead = $foundLead;

                if ($hasConflict) {
                    $this->logger->debug('FORM: Conflicts found in '.implode(', ', $conflicts).' so not merging');
                } else {
                    $this->logger->debug('FORM: In kiosk mode so not merging');
                }
            } else {
                $this->logger->debug('FORM: Merging contacts '.$lead->getId().' and '.$foundLead->getId());

                // Merge the found lead with currently tracked lead
                $lead = $this->leadModel->mergeLeads($lead, $foundLead);
            }

            // Update unique fields data for comparison with submitted data
            $currentFields       = $lead->getProfileFields();
            $uniqueFieldsCurrent = $getData($currentFields, true);
        }

        if (!$inKioskMode) {
            // Check for conflicts with the submitted data and the currently tracked lead
            list($hasConflict, $conflicts) = $checkForIdentifierConflict($uniqueFieldsWithData, $uniqueFieldsCurrent);

            $this->logger->debug(
                'FORM: Current unique contact fields '.implode(', ', array_keys($uniqueFieldsCurrent)).' = '.implode(', ', $uniqueFieldsCurrent)
            );

            $this->logger->debug(
                'FORM: Submitted unique contact fields '.implode(', ', array_keys($uniqueFieldsWithData)).' = '.implode(', ', $uniqueFieldsWithData)
            );
            if ($hasConflict) {
                // There's a conflict so create a new lead
                $lead = new Lead();
                $lead->setNewlyCreated(true);

                $this->logger->debug(
                    'FORM: Conflicts found in '.implode(', ', $conflicts)
                    .' between current tracked contact and submitted data so assuming a new contact'
                );
            }
        }

        //check for existing IP address
        $ipAddress = $this->ipLookupHelper->getIpAddress();

        //no lead was found by a mapped email field so create a new one
        if ($lead->isNewlyCreated()) {
            if (!$inKioskMode) {
                $lead->addIpAddress($ipAddress);
                $this->logger->debug('FORM: Associating '.$ipAddress->getIpAddress().' to contact');
            }
        } elseif (!$inKioskMode) {
            $leadIpAddresses = $lead->getIpAddresses();
            if (!$leadIpAddresses->contains($ipAddress)) {
                $lead->addIpAddress($ipAddress);

                $this->logger->debug('FORM: Associating '.$ipAddress->getIpAddress().' to contact');
            }
        }
        if (empty($lead->getStatus())) {
            $lead->setStatus(1);
        }
        if (empty($lead->getScore())) {
            $lead->setScore('cold');
        }
        //set the mapped fields
        $this->leadModel->setFieldValues($lead, $data, false, true, true);

        // last active time
        $lead->setLastActive(new \DateTime());

        //create a new lead
        $lead->setManipulator(new LeadManipulator(
            'form',
            'submission',
            $form->getId(),
            $form->getName()
        ));

        $lead->setCreatedSource(3); //Created Source FORM SUBMIT
        $this->leadModel->saveEntity($lead, false);

        if (!$inKioskMode) {
            // Set the current lead which will generate tracking cookies
            $this->leadModel->setCurrentLead($lead);
        } else {
            // Set system current lead which will still allow execution of events without generating tracking cookies
            $this->leadModel->setSystemCurrentLead($lead);
        }

        $companyFieldMatches = $getCompanyData($leadFieldMatches);
        if (!empty($companyFieldMatches)) {
            list($company, $leadAdded, $companyEntity) = IdentifyCompanyHelper::identifyLeadsCompany($companyFieldMatches, $lead, $this->companyModel);
            if ($leadAdded) {
                $lead->addCompanyChangeLogEntry('form', 'Identify Company', 'Lead added to the company, '.$company['companyname'], $company['id']);
            }

            if (!empty($company) and $companyEntity instanceof Company) {
                // Save after the lead in for new leads created through the API and maybe other places
                $this->companyModel->addLeadToCompany($companyEntity, $lead);
                $this->leadModel->setPrimaryCompany($companyEntity->getId(), $lead->getId());
            }
            $this->em->clear(CompanyChangeLog::class);
        }
        if ($leadId = $lead->getId()) {
            $this->leadModel->setLeadCookie($leadId);
        }

        return $lead;
    }

    /**
     * Validates a field value.
     *
     * @param Field $field
     * @param       $value
     *
     * @return bool|string True if valid; otherwise string with invalid reason
     */
    protected function validateFieldValue(Field $field, $value)
    {
        $standardValidation = $this->fieldHelper->validateFieldValue($field->getType(), $value);
        if (!empty($standardValidation)) {
            return $standardValidation;
        }

        $components = $this->formModel->getCustomComponents();
        foreach ([$field->getType(), 'form'] as $type) {
            if (isset($components['validators'][$type])) {
                if (!is_array($components['validators'][$type])) {
                    $components['validators'][$type] = [$components['validators'][$type]];
                }
                foreach ($components['validators'][$type] as $validator) {
                    if (!is_array($validator)) {
                        $validator = ['eventName' => $validator];
                    }
                    $event = $this->dispatcher->dispatch($validator['eventName'], new ValidationEvent($field, $value));
                    if (!$event->isValid()) {
                        return $event->getInvalidReason();
                    }
                }
            }
        }

        return true;
    }

    /**
     * @deprecated - added for BC; to be removed in 3.0
     *
     * @param Action[]        $actions
     * @param SubmissionEvent $event
     * @param                 $validationErrors
     * @param                 $lastAlias        Because prior to now the last alias was used regardless
     */
    protected function validateActionCallbacks(SubmissionEvent $event, &$validationErrors, $lastAlias)
    {
        $args = [
            'post'       => $event->getPost(),
            'server'     => $event->getServer(),
            'factory'    => $this->factory, // WHAT??
            'submission' => $event->getSubmission(),
            'fields'     => $event->getFields(),
            'form'       => $event->getSubmission()->getForm(),
            'tokens'     => $event->getTokens(),
        ];

        $actions          = $event->getSubmission()->getForm()->getActions();
        $availableActions = $this->formModel->getCustomComponents()['actions'];

        foreach ($actions as $action) {
            $key = $action->getType();
            if (!isset($availableActions[$key])) {
                continue;
            }

            $settings       = $availableActions[$key];
            $args['action'] = $action;
            $args['config'] = $action->getProperties();
            if (array_key_exists('validator', $settings)) {
                $callback = $settings['validator'];
                if (is_callable($callback)) {
                    if (is_array($callback)) {
                        $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                    } elseif (strpos($callback, '::') !== false) {
                        $parts      = explode('::', $callback);
                        $reflection = new \ReflectionMethod($parts[0], $parts[1]);
                    } else {
                        $reflection = new \ReflectionMethod(null, $callback);
                    }

                    $pass = [];
                    foreach ($reflection->getParameters() as $param) {
                        if (isset($args[$param->getName()])) {
                            $pass[] = $args[$param->getName()];
                        } else {
                            $pass[] = null;
                        }
                    }
                    list($validated, $validatedMessage) = $reflection->invokeArgs($this, $pass);
                    if (!$validated) {
                        $validationErrors[$lastAlias] = $validatedMessage;
                    }
                }
            }
        }

        unset($args, $actions, $availableActions);
    }

    /**
     * @param Form $form
     *
     * @return array
     */
    private function getSmartFormFields(Form $form)
    {
        $smartformfields=[];
        $fields         =$form->getSmartFields();
        foreach ($fields as $index=>$field) {
            $newfield=new Field();
            $newfield->setId($index);
            $newfield->setType('text');
            $newfield->setAlias($field['dbfield']);
            $newfield->setLeadField($field['leadfield']);
            $newfield->setIsRequired(false);
            $newfield->setIsCustom(false);
            $newfield->setDbField($field['smartfield']);
            $smartformfields[]=$newfield;
        }

        return $smartformfields;
    }

    private function verifyGoogleCaptchaResponse($response)
    {
        $payload=['response'=>$response, 'secret'=>$this->leadModel->getCoreParameterHelper()->getParameter('gcaptcha_secret_key')];
        $ch     = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($payload)));
        $result = curl_exec($ch);
        $result =json_decode($result);
        if (isset($result->success) && $result->success) {
            return true;
        } else {
            return false;
        }
        //{ "success": true, "challenge_ts": "2019-05-08T12:20:14Z", "hostname": "localhost" }
    }
}
