<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Form\Type;

use Mautic\CoreBundle\Form\ToBcBccFieldsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Class EmailToUserType.
 */
class EmailToUserType extends AbstractType
{
    use ToBcBccFieldsTrait;

    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('useremail', 'emailsend_list', [
            'label' => 'le.email.emails',
            'attr'  => [
                'class'   => 'form-control',
                'tooltip' => 'le.email.choose.emails_descr',
            ],
            'update_select'   => empty($options['update_select']) ? 'formaction_properties_useremail_email' : $options['update_select'],
            'with_email_types'=> !empty($options['with_email_types']) ? $options['with_email_types'] : 'false',
            'set_email_list_type' => 'template',
        ]);

        $builder->add('user_id', 'user_list', [
            'label'      => 'mautic.email.form.users',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.core.help.autocomplete',
            ],
            'required' => false,
        ]);

        $builder->add(
            'to_owner',
            'yesno_button_group',
            [
                'label' => 'le.form.action.send.email.to.owner',
                'data'  => isset($options['data']['to_owner']) ? $options['data']['to_owner'] : false,
            ]
        );

        $this->addToBcBccFields($builder);
    }

    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'label'            => false,
            'with_email_types' => false,
        ]);

        $resolver->setDefined(['update_select', 'with_email_types']);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'email_to_user';
    }
}
