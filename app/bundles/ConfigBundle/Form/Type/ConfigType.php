<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\ConfigBundle\Form\Type;

use Mautic\ConfigBundle\Form\Helper\RestrictionHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Class ConfigType.
 */
class ConfigType extends AbstractType
{
    /**
     * @var RestrictionHelper
     */
    private $restrictionHelper;

    /**
     * ConfigType constructor.
     *
     * @param RestrictionHelper $restrictionHelper
     */
    public function __construct(RestrictionHelper $restrictionHelper)
    {
        $this->restrictionHelper = $restrictionHelper;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        foreach ($options['data'] as $config) {
            if (isset($config['formAlias']) && !empty($config['parameters'])) {
                $checkThese = array_intersect(array_keys($config['parameters']), $options['fileFields']);
                foreach ($checkThese as $checkMe) {
                    // Unset base64 encoded values
                    unset($config['parameters'][$checkMe]);
                }
                $builder->add(
                    $config['formAlias'],
                    $config['formAlias'],
                    [
                        'data' => $config['parameters'],
                    ]
                );
            }
        }

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($options) {
                $form = $event->getForm();

                foreach ($form as $config => $configForm) {
                    foreach ($configForm as $child) {
                        $this->restrictionHelper->applyRestrictions($child, $configForm);
                    }
                }
            }
        );
        if (count($options['fileFields']) > 0) {
            $builder->add(
                'buttons',
                'form_buttons',
                [
                    'pre_extra_buttons' => [
                    ],
                    'apply_text'    => 'mautic.core.form.save',
                    'apply_onclick' => 'Le.activateBackdrop()',
                    'save_onclick'  => 'Le.activateBackdrop()',
                ]
            );
        } else {
            $builder->add(
                'buttons',
                'form_buttons',
                [
                    'pre_extra_buttons' => [
                        [
                            'name'  => 'sendingdomain',
                            'label' => 'le.config.tab.sendingdomain.add',
                            'attr'  => [
                                'class' => 'btn btn-default le-btn-default sendingdomain_config hide',
                            ],
                        ],
                    ],
                    'apply_text'    => false,
                    'save_text'     => false,
                    'cancel_text'   => false,
                    'apply_onclick' => 'Le.activateBackdrop()',
                    'save_onclick'  => 'Le.activateBackdrop()',
                ]
            );
        }

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'config';
    }

    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            [
                'fileFields' => [],
            ]
        );
    }
}
