<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Form\Type;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\EmailBundle\Form\Validator\Constraints\EmailVerify;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Class EmailType.
 */
class EmailType extends AbstractType
{
    /**
     * @var MauticFactory
     */
    private $factory;

    public function __construct(MauticFactory $factory)
    {
        $this->factory        = $factory;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addEventSubscriber(new CleanFormSubscriber(['body' => 'html']));
        $emailProvider = $this->factory->get('mautic.helper.licenseinfo')->getEmailProvider();
        $currentUser   = $this->factory->get('mautic.helper.user')->getUser()->isAdmin();
        /** @var \Mautic\CoreBundle\Configurator\Configurator $configurator */
        $configurator  = $this->factory->get('mautic.configurator');
        $params        = $configurator->getParameters();
        $fromname      =  $params['mailer_from_name'];
        $fromemail     = $params['mailer_from_email'];
        $disabled      = false;

        if (!$currentUser) {
            if ($emailProvider == 'Sparkpost') {
                $disabled = true;
            }
        }

        $builder->add(
            'subject',
            'text',
            [
                'label'      => 'mautic.email.subject',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class' => 'form-control'],
                'required'   => false,
            ]
        );

        $user = $this->factory->get('mautic.helper.user')->getUser();
        if ($emailProvider == 'Sparkpost') {
            $default = (empty($options['data']['fromname'])) ? $fromname : $options['data']['fromname'];
        } else {
            $default = (empty($options['data']['fromname'])) ? $user->getFirstName().' '.$user->getLastName() : $options['data']['fromname'];
        }

        $builder->add(
            'fromname',
            'text',
            [
                'label'      => 'mautic.lead.email.from_name',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => ['class'     => 'form-control',
                'disabled'                   => false,
                ],
                'required'   => false,
                'data'       => $default,
            ]
        );

        if ($emailProvider == 'Sparkpost') {
            $default = (empty($options['data']['from'])) ? $fromemail : $options['data']['from'];
        } else {
            $default = (empty($options['data']['from'])) ? $user->getEmail() : $options['data']['from'];
        }

        $builder->add(
            'from',
            'text',
            [
                'label'       => 'mautic.lead.email.from_email',
                'label_attr'  => ['class' => 'control-label'],
                'attr'        => ['class'   => 'form-control',
                    'disabled'              => $disabled,
                ],
                'required'    => false,
                'data'        => $default,
                'constraints' => [
                    new NotBlank([
                        'message' => 'mautic.core.email.required',
                    ]),
                    new Email([
                        'message' => 'mautic.core.email.required',
                    ]),
                    new EmailVerify(
                        [
                            'message' => 'le.email.verification.error',
                        ]
                    ),
                ],
            ]
        );

        $builder->add(
            'body',
            'textarea',
            [
                'label'      => 'mautic.email.form.body',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'                => 'form-control editor editor-basic-fullpage editor-builder-tokens editor-email',
                    'data-token-callback'  => 'email:getBuilderTokens',
                    'data-token-activator' => '{',
                ],
            ]
        );

        $builder->add('list', 'hidden');

        $builder->add(
            'templates',
            'email_list',
            [
                'label'      => 'mautic.lead.email.template',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'    => 'form-control',
                    'onchange' => 'Mautic.getLeadEmailContent(this)',
                ],
                'multiple' => false,
            ]
        );

        $builder->add('buttons', 'form_buttons', [
            'apply_text'  => false,
            'save_text'   => 'mautic.email.send',
            'save_class'  => 'le-btn-default',
            'save_icon'   => 'fa fa-send',
            'cancel_icon' => 'fa fa-times',
        ]);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'lead_quickemail';
    }
}
