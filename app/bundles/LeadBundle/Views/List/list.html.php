<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
//Check to see if the entire page should be displayed or just main content
if ($tmpl == 'index'):
    $view->extend('MauticLeadBundle:List:index.html.php');
endif;
$listCommand = $view['translator']->trans('le.lead.lead.searchcommand.list');
$isAdmin     =$view['security']->isAdmin();
?>

<?php if (count($items)): ?>
    <div class="table-responsive">
        <table class="table table-hover table-striped table-bordered" id="leadListTable">
            <thead>
            <tr>
                <?php
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'checkall'        => 'true',
                        'target'          => '#leadListTable',
                        'langVar'         => 'lead.list',
                        'routeBase'       => 'segment',
                        'templateButtons' => [
                            'delete' => $permissions['lead:lists:deleteother'],
                        ],
                    ]
                );
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => '',
                        'text'       => 'mautic.core.update.heading.status',
                        'class'      => 'col-status-name',
                        'default'    => true,
                    ]
                );
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'segment',
                        'orderBy'    => 'l.name',
                        'text'       => 'mautic.core.name',
                        'class'      => 'col-leadsegment-name',
                    ]
                );

                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'segment',
                        'text'       => 'le.lead.list.thead.leadcount',
                        'class'      => 'col-leadlist-leadcount text-center',
                    ]
                );

                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'segment',
                        'text'       => 'le.lead.list.thead.inactive.leadcount',
                        'class'      => 'col-leadlist-leadcount text-center',
                    ]
                );
                if ($isAdmin):
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'segment',
                        'orderBy'    => 'l.id',
                        'text'       => 'mautic.core.id',
                        'class'      => 'col-leadlist-id',
                    ]
                );
                endif;
                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'lead',
                    'orderBy'    => '',
                    'text'       => 'mautic.core.actions',
                    'class'      => 'col-lead-location col-lead-actions',
                ]);
                ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?php
                        echo $view->render(
                            'MauticCoreBundle:Helper:list_actions.html.php',
                            [
                                'item'            => $item,
                                'templateButtons' => [
                                    'edit'   => $view['security']->hasEntityAccess(true, $permissions['lead:lists:editother'], $item->getCreatedBy()),
                                    'clone'  => $view['security']->hasEntityAccess(true, $permissions['lead:lists:editother'], $item->getCreatedBy()),
                                    'delete' => $view['security']->hasEntityAccess(true, $permissions['lead:lists:deleteother'], $item->getCreatedBy()),
                                ],
                                'routeBase' => 'segment',
                                'langVar'   => 'lead.list',
                                'custom'    => [
                                    [
                                        'attr' => [
                                            'data-toggle' => 'ajax',
                                            'href'        => $view['router']->path(
                                                'le_contact_index',
                                                [
                                                    'search' => "$listCommand:{$item->getAlias()}",
                                                ]
                                            ),
                                        ],
                                        'icon'  => 'fa-users',
                                        'label' => 'le.lead.list.view_leads',
                                    ],
                                ],
                            ]
                        );
                        ?>
                    </td>
                    <td>
                        <?php echo $view->render(
                            'MauticCoreBundle:Helper:publishstatus_icon.html.php',
                            ['item' => $item, 'model' => 'lead.list']
                        ); ?>
                    </td>
                    <td class="table-description">
                        <div>
                            <?php if ($view['security']->hasEntityAccess(true, $permissions['lead:lists:editother'], $item->getCreatedBy())) : ?>
                                <a href="<?php echo $view['router']->path(
                                    'le_segment_action',
                                    ['objectAction' => 'edit', 'objectId' => $item->getId()]
                                ); ?>" data-toggle="tooltip">
                                    <?php echo $item->getName(); ?> <!--(<?php /** echo $item->getAlias(); */ ?>) -->
                                </a>
                            <?php else : ?>
                                <?php echo $item->getName(); ?> <!-- (<?php /** echo $item->getAlias(); */ ?>)-->
                            <?php endif; ?>
                            <?php if (!$item->isGlobal() && $currentUser->getId() != $item->getCreatedBy()): ?>
                                <br/>
                                <span class="small">(<?php echo $item->getCreatedByUser(); ?>)</span>
                            <?php endif; ?>
                            <?php if ($isAdmin):?>
                            <?php if ($item->isGlobal()): ?>
                            <span data-toggle="tooltip" title="<?php echo $view['translator']->trans(
                                'le.lead.list.form.isglobal'
                            ); ?>"><i class="fa fa-fw fa-globe"></i></span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($description = $item->getDescription()): ?>
                            <div class="text-muted mt-4">
                                <small><?php echo $description; ?></small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center" style="width: 12%;">
                        <a class="label label-primary" style="background-color: #5cb45b;" href="<?php echo $view['router']->path(
                            'le_contact_index',
                            ['search' => $view['translator']->trans('le.lead.lead.searchcommand.list.active').':'.$item->getAlias()]
                        ); ?>" data-toggle="tooltip"<?php echo ($ActiveLeadCounts[$item->getId()] == 0) ? 'disabled=disabled' : ''; ?>>
                            <?php echo $view['translator']->transChoice(
                                'le.lead.list.viewleads_count',
                                $ActiveLeadCounts[$item->getId()],
                                ['%count%' => $ActiveLeadCounts[$item->getId()]]
                            ); ?>
                        </a>
                    </td>
                    <td class="text-center" style="width: 13%;">
                        <a class="label label-primary" style="background-color: #f03154;" href="<?php echo $view['router']->path(
                            'le_contact_index',
                            ['search' => $view['translator']->trans('le.lead.lead.searchcommand.list.inactive').':'.$item->getAlias()]
                        ); ?>" data-toggle="tooltip"<?php echo ($InactiveLeadCounts[$item->getId()] == 0) ? 'disabled=disabled' : ''; ?>>
                            <?php echo $view['translator']->transChoice(
                                'le.lead.list.viewleads_count',
                                $InactiveLeadCounts[$item->getId()],
                                ['%count%' => $InactiveLeadCounts[$item->getId()]]
                            ); ?>
                        </a>
                    </td>
                    <?php  if ($isAdmin):?>
                    <td class=""><?php echo $item->getId(); ?></td>
                    <?php  endif; ?>
                    <td>
                        <?php $hasEditAccess   = $view['security']->hasEntityAccess(true, $permissions['lead:lists:editother'], $item->getCreatedBy());
                              $hasDeleteAccess = $view['security']->hasEntityAccess(true, $permissions['lead:lists:deleteother'], $item->getCreatedBy());
                              $hasCloneAccess  = $view['security']->hasEntityAccess(true, $permissions['lead:lists:editother'], $item->getCreatedBy()); ?>
                        <div style="position: relative;" class="fab-list-container">
                            <div class="md-fab-wrapper">
                                <div class="md-fab md-fab-toolbar md-fab-small md-fab-primary" id="mainClass-<?php echo $item->getId(); ?>" style="">
                                    <i class="material-icons" onclick="Le.showActionButtons('<?php echo $item->getId(); ?>')"></i>
                                    <div tabindex="0" class="md-fab-toolbar-actions toolbar-actions-<?php echo $item->getId(); ?>">
                                        <?php if ($hasEditAccess): ?>
                                            <a class="hidden-xs-sm -nospin" title="<?php echo $view['translator']->trans('mautic.core.form.edit'); ?>" href="<?php echo $view['router']->path('le_segment_action', ['objectAction' => 'edit', 'objectId' => $item->getId()]); ?>" data-toggle="tooltip">
                                                <span><i class="material-icons md-color-white">  </i></span></a>
                                        <?php endif; ?>
                                        <?php if ($hasCloneAccess) : ?>
                                            <a class="hidden-xs" title="<?php echo $view['translator']->trans('mautic.core.form.clone'); ?>" href="<?php echo $view['router']->path('le_segment_action', ['objectId' => $item->getId(), 'objectAction' => 'clone']); ?>" data-toggle="tooltip" data-uk-tooltip="">
                                            <i class="material-icons md-color-white">  </i> </a>
                                        <?php endif; ?>
                                        <?php if ($hasDeleteAccess):?>
                                            <a data-toggle="confirmation" href="<?php echo $view['router']->path('le_segment_action', ['objectAction' => 'delete', 'objectId' => $item->getId()]); ?>" data-message="<?php echo $view->escape($view['translator']->trans('le.lead.list.form.confirmdelete', ['%name%'=> $item->getName()])); ?>" data-confirm-text="<?php echo $view->escape($view['translator']->trans('mautic.core.form.delete')); ?>" data-confirm-callback="executeAction" title="<?php echo $view['translator']->trans('mautic.core.form.delete'); ?>" data-cancel-text="<?php echo $view->escape($view['translator']->trans('mautic.core.form.cancel')); ?>">
                                                <span><i class="material-icons md-color-white">  </i></span>
                                            </a>

                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="panel-footer">
            <?php echo $view->render(
                'MauticCoreBundle:Helper:pagination.html.php',
                [
                    'totalItems' => count($items),
                    'page'       => $page,
                    'limit'      => $limit,
                    'baseUrl'    => $view['router']->path('le_segment_index'),
                    'sessionVar' => 'segment',
                ]
            ); ?>
        </div>
    </div>
<?php else: ?>
    <?php echo $view->render('MauticCoreBundle:Helper:noresults.html.php'); ?>
<?php endif; ?>
