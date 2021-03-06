<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Security\Permissions;

use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class LeadPermissions.
 */
class LeadPermissions extends AbstractPermissions
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->permissions = [
            'lists' => [
                'viewother'   => 2,
                'editother'   => 8,
                'deleteother' => 64,
                'full'        => 1024,
            ],
            'fields' => [
                'full' => 1024,
            ],
            'listoptin' => [
                'viewother'   => 2,
                'editother'   => 8,
                'deleteother' => 64,
                'full'        => 1024,
            ],
            'tags' => [
                'full'        => 1024,
            ],
            'notes' => [
                'full'        => 1024,
            ],
        ];
        $this->addExtendedPermissions('leads', false);
        $this->addStandardPermissions('imports');
    }

    /**
     * {@inheritdoc}
     *
     * @return string|void
     */
    public function getName()
    {
        return 'lead';
    }

    /**
     * {@inheritdoc}
     *
     * @param FormBuilderInterface $builder
     * @param array                $options
     * @param array                $data
     */
    public function buildForm(FormBuilderInterface &$builder, array $options, array $data)
    {
        $this->addExtendedFormFields('lead', 'leads', $builder, $data, false);

        $builder->add('lead:lists', 'permissionlist', [
            'choices' => [
                'viewother'   => 'mautic.core.permissions.viewother',
                'editother'   => 'mautic.core.permissions.editother',
                'deleteother' => 'mautic.core.permissions.deleteother',
                'full'        => 'mautic.core.permissions.full',
            ],
            'label'  => 'le.lead.permissions.lists',
            'data'   => (!empty($data['lists']) ? $data['lists'] : []),
            'bundle' => 'lead',
            'level'  => 'lists',
        ]);

        $builder->add('lead:fields', 'permissionlist', [
            'choices' => [
                'full' => 'mautic.core.permissions.manage',
            ],
            'label'  => 'le.lead.permissions.fields',
            'data'   => (!empty($data['fields']) ? $data['fields'] : []),
            'bundle' => 'lead',
            'level'  => 'fields',
        ]);

        $builder->add('lead:listoptin', 'permissionlist', [
            'choices' => [
                'viewother'   => 'mautic.core.permissions.viewother',
                'editother'   => 'mautic.core.permissions.editother',
                'deleteother' => 'mautic.core.permissions.deleteother',
                'full'        => 'mautic.core.permissions.full',
            ],
            'label'  => 'le.lead.permissions.listoptin',
            'data'   => (!empty($data['listoptin']) ? $data['listoptin'] : []),
            'bundle' => 'lead',
            'level'  => 'listoptin',
        ]);

        $builder->add('lead:tags', 'permissionlist', [
            'choices' => [
                'full'        => 'mautic.core.permissions.full',
            ],
            'label'  => 'le.lead.permissions.tags',
            'data'   => (!empty($data['tags']) ? $data['tags'] : []),
            'bundle' => 'lead',
            'level'  => 'tags',
        ]);

        $builder->add('lead:notes', 'permissionlist', [
            'choices' => [
                'full'        => 'mautic.core.permissions.full',
            ],
            'label'  => 'le.lead.permissions.notes',
            'data'   => (!empty($data['notes']) ? $data['notes'] : []),
            'bundle' => 'lead',
            'level'  => 'notes',
        ]);

        $this->addStandardFormFields($this->getName(), 'imports', $builder, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function analyzePermissions(array &$permissions, $allPermissions, $isSecondRound = false)
    {
        parent::analyzePermissions($permissions, $allPermissions, $isSecondRound);

        //make sure the user has access to own leads as well if they have access to lists, notes or fields
        $viewPerms = ['viewown', 'viewother', 'full'];
        if (
            (!isset($permissions['leads']) || (array_intersect($viewPerms, $permissions['leads']) == $viewPerms)) &&
            (isset($permissions['lists']) || isset($permission['fields']) || isset($permission['listoptin']) || isset($permission['tags']) || isset($permission['notes']))
        ) {
            $permissions['leads'][] = 'viewown';
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param $name
     * @param $level
     *
     * @return array
     */
    protected function getSynonym($name, $level)
    {
        if ($name === 'fields') {
            //set some synonyms
            switch ($level) {
                case 'publishown':
                case 'publishother':
                case 'view':
                    $level = 'full';
                    break;
            }
        }

        if ($name === 'lists') {
            switch ($level) {
                case 'view':
                case 'viewown':
                    $name = 'leads';
                    break;
            }
        }

        if ($name === 'listoptin') {
            switch ($level) {
                case 'view':
                case 'viewown':
                    $name = 'leads';
                    break;
            }
        }
        if ($name === 'tags') {
            switch ($level) {
                case 'view':
                case 'publishown':
                case 'publishother':
                case 'viewown':
                case 'viewother':
                    $level = 'full';
                    break;
            }
        }

        if ($name === 'notes') {
            switch ($level) {
                case 'publishown':
                case 'publishother':
                case 'view':
                case 'viewown':
                case 'viewother':
                case 'editown':
                case 'editother':
                case 'deleteown':
                case 'deleteother':
                    $level = 'full';
                    break;
            }
        }

        return parent::getSynonym($name, $level);
    }
}
