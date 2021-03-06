<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
if ($tmpl == 'index') {
    $view->extend('MauticEmailBundle:Email:index.html.php');
}
$isAdmin=$view['security']->isAdmin();
?>
<div class="newbutton-container <?php echo $notificationemail ? 'hide' : ''?>" style="margin-top:-25px;position:relative;top:-40px;margin-right:15px;">
    <li class="dropdown dropdown-menu-right" style="display: block;float:right;">
        <a class="btn btn-nospin le-btn-default" style="position: relative;font-size: 14px;top: 0;vertical-align: super;" data-toggle="dropdown" href="#">
            <span><i class="fa fa-plus"></i><span class=""> <?php echo $view['translator']->trans('le.email.new')?></span></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-right" style="margin-top: 21px;">
            <div class="insert-drip-options">
                <div class='drip-options-panel'>
                    <h1 style='font-size:16px;font-weight:bold;'><?php echo $view['translator']->trans('le.email.editor.header')?></h1>
                    <br>
                    <div class="row">
                        <div class="col-md-6 editor_layout fl-left <?php echo $ismobile ? 'hide' : ''?>"  style="margin-left:10px;"><!--onclick="Le.setValueforNewButton('advance_editor',this);"-->
                            <a data-toggle="ajaxmodal" data-target="#leSharedModal" href="<?php echo $view['router']->path('le_email_campaign_action', ['objectAction' => 'quickadd', 'objectId' => 1]); ?>" data-header="<?php echo $view['translator']->trans('le.email.quickadd.header.title')?>">
                                <img height="100px" width="auto" src="<?php echo $view['assets']->getUrl('media/images/drag-drop.png')?>"/>
                                <h4><?php echo $view['translator']->trans('le.email.editor.advance.header')?></h4>
                                <br>
                            </a>
                        </div>
                        <div class="col-md-6 editor_layout fl-left editor_select" style="margin-left:20px;"> <!--onclick="Le.setValueforNewButton('basic_editor',this);"-->
                            <a data-toggle="ajaxmodal" data-target="#leSharedModal" href="<?php echo $view['router']->path('le_email_campaign_action', ['objectAction' => 'quickadd', 'objectId' => 0]); ?>" data-header="<?php echo $view['translator']->trans('le.email.quickadd.header.title')?>">
                                <img height="100px" width="auto" src="<?php echo $view['assets']->getUrl('media/images/rich-text.png')?>"/>
                                <h4><?php echo $view['translator']->trans('le.email.editor.basic.header.drip')?></h4>
                                <br>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </ul>
    </li>

    <a class="btn hide btn-default le-btn-default btn-nospin" id="new-drip-email" value="basic_editor" onclick="Le.openDripEmailEditor();" style="float:right;z-index:10000;">
        <span><i class="fa fa-plus"></i><span class="hidden-xs hidden-sm"> <?php echo $view['translator']->trans('le.drip.email.new.email')?></span></span>
    </a>
</div>
<?php if (count($items)): ?>
    <div class="table-responsive table-responsive-force">
        <table class="table table-hover table-striped table-bordered email-list">
            <thead>
            <tr>
                <?php
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'checkall'          => 'true',
                        'actionRoute'       => $actionRoute,
                        'templateButtons'   => [
                            'delete' => $permissions['email:emails:deleteown'] || $permissions['email:emails:deleteother'],
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
                        'sessionVar' => 'email',
                        'orderBy'    => 'e.name',
                        'text'       => 'mautic.core.name',
                        'class'      => 'col-notification-email-name',
                        'default'    => true,
                    ]
                );
                if (!$notificationemail) {
                    echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => '',
                        'text'       => 'le.email.graph.line.stats.pending',
                        'class'      => 'col-email-stats',
                        'default'    => true,
                    ]
                );
                }
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => '',
                        'text'       => 'le.email.graph.line.stats.sent',
                        'class'      => 'col-email-stats',
                        'default'    => true,
                    ]
                );
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => '',
                        'text'       => 'le.email.label.list.reads',
                        'class'      => 'col-email-stats',
                        'default'    => true,
                    ]
                );
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => '',
                        'text'       => 'le.email.report.hits_count',
                        'class'      => 'col-email-stats',
                        'default'    => true,
                    ]
                );
                if ($isAdmin || !$notificationemail) {
                    echo $view->render(
                        'MauticCoreBundle:Helper:tableheader.html.php',
                        [
                            'sessionVar' => 'email',
                            'orderBy'    => '',
                            'text'       => 'le.email.token.unsubscribes_text',
                            'class'      => 'col-email-stats',
                            'default'    => true,
                        ]
                    );
                }
                if ($isAdmin) {//!$notificationemail ||
                    echo $view->render(
                        'MauticCoreBundle:Helper:tableheader.html.php',
                        [
                            'sessionVar' => 'email',
                            'orderBy'    => '',
                            'text'       => 'le.email.config.monitored_email.bounce_folder',
                            'class'      => 'col-email-stats',
                            'default'    => true,
                        ]
                    );
                    echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => '',
                        'text'       => 'le.email.email.spams',
                        'class'      => 'col-email-stats',
                        'default'    => true,
                    ]
                );
                    echo $view->render(
                        'MauticCoreBundle:Helper:tableheader.html.php',
                        [
                            'sessionVar' => 'email',
                            'orderBy'    => '',
                            'text'       => 'le.email.email.failed',
                            'class'      => 'col-email-stats',
                            'default'    => true,
                        ]
                    );
                }
                if ($isAdmin):
                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'email',
                        'orderBy'    => 'e.id',
                        'text'       => 'mautic.core.id',
                        'class'      => 'col-email-id',
                    ]
                );
                endif;

                echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', [
                    'sessionVar' => 'email',
                    'orderBy'    => '',
                    'text'       => 'mautic.core.actions',
                    'class'      => 'col-lead-location notification-action-btn',
                ]);
                ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                $hasVariants                = $item->isVariant();
                $hasTranslations            = $item->isTranslation();
                $type                       = $item->getEmailType();
                $mauticTemplateVars['item'] = $item;
                ?>
                <tr class="email-row-stats" data-stats="<?php echo $item->getId(); ?>">
                    <td>
                        <?php
                        $edit = $view['security']->hasEntityAccess(
                            $permissions['email:emails:editown'],
                            $permissions['email:emails:editother'],
                            $item->getCreatedBy()
                        );
                        $customButtons = ($type == 'list') ? [
                            [
                                'attr' => [
                                    'data-toggle' => 'ajax',
                                    'href'        => $view['router']->path(
                                        $actionRoute,
                                        ['objectAction' => 'send', 'objectId' => $item->getId()]
                                    ),
                                ],
                                'iconClass' => 'fa fa-send-o',
                                'btnText'   => 'le.email.send',
                            ],
                        ] : [];
                        echo $view->render(
                            'MauticCoreBundle:Helper:list_actions.html.php',
                            [
                                'item'            => $item,
                                'templateButtons' => [
                                    'edit'   => $edit,
                                    'clone'  => $permissions['email:emails:create'],
                                    'delete' => $view['security']->hasEntityAccess(
                                        $permissions['email:emails:deleteown'],
                                        $permissions['email:emails:deleteother'],
                                        $item->getCreatedBy()
                                    ),
                                    'abtest' => (!$hasVariants && $edit && $permissions['email:emails:create']),
                                ],
                                'actionRoute'       => $actionRoute,
                                'customButtons'     => $customButtons,
                                'translationBase'   => $translationBase,
                            ]
                        );
                        ?>
                    </td>
                    <td>
                        <?php echo $view->render('MauticCoreBundle:Helper:publishstatus_icon.html.php', ['item' => $item, 'model' => 'email']); ?>
                    </td>
                    <td class="table-description">
                        <div>
                            <?php $category = $item->getCategory(); ?>
                            <?php $catName  = ($category) ? $category->getTitle() : $view['translator']->trans('mautic.core.form.uncategorized'); ?>
                            <?php $color    = ($category) ? '#'.$category->getColor() : 'inherit'; ?>
                            <a href="<?php echo $view['router']->path(
                                $actionRoute,
                                ['objectAction' => $notificationemail ? 'edit' : 'view', 'objectId' => $item->getId()]
                            ); ?>" data-toggle="ajax">
                                <?php echo $item->getName(); ?>
                                <?php if ($hasVariants): ?>
                                    <span data-toggle="tooltip" title="<?php echo $view['translator']->trans('mautic.core.icon_tooltip.ab_test'); ?>">
                                    <i class="fa fa-fw fa-sitemap"></i>
                                </span>
                                <?php endif; ?>
                                <?php if ($hasTranslations): ?>
                                    <span data-toggle="tooltip" title="<?php echo $view['translator']->trans(
                                        'mautic.core.icon_tooltip.translation'
                                    ); ?>">
                                    <i class="fa fa-fw fa-language"></i>
                                </span>
                                <?php endif; ?>
                                <?php if (!$notificationemail): ?>
                                <?php if ($type !== 'list'): ?>
                                    <span data-toggle="tooltip" title="<?php echo $view['translator']->trans(
                                        'le.email.icon_tooltip.list_email'
                                    ); ?>">
                                    <i class="fa fa-fw fa-pie-chart"></i>
                                </span>
                                <?php endif; ?>
                                <?php echo $view['content']->getCustomContent('email.name', $mauticTemplateVars); ?>
                            </a>
                            <div style="white-space: nowrap;">
                            <span class="label label-default pa-4" style="border: 1px solid #d5d5d5; background: <?php echo $color; ?>;"> </span> <span><?php echo $catName; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($description = $item->getDescription()): ?>
                            <div class="text-muted mt-4">
                                <small><?php echo $description; ?></small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php if (!$notificationemail): ?>
                    <td class="col-stats" data-stats="<?php echo $item->getId(); ?>">
                      <span class="mt-xs has-click-event clickable-stat"
                            id="pending-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_pending').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.pending.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.leadcount', ['%count%' => $item->getPendingCount()]); ?>
                            </a>
                        </span>
                    </td>
                    <?php endif; ?>
                   <td class="col-stats" data-stats="<?php echo $item->getId(); ?>">
                    <span class="mt-xs has-click-event clickable-stat"
                          id="sent-count-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_sent').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.tooltip.sent'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.leadcount', ['%count%' => $item->getSentCount(true)]); ?>
                            </a>
                        </span>
                   </td>
                   <td class="col-stats" data-stats="<?php echo $item->getId(); ?>">
                     <span class="mt-xs has-click-event clickable-stat"
                           id="read-count-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_read').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.read.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.readcount', ['%count%' => $item->getReadCount(true), '%percentage%' => round($item->getReadPercentage(true))]); ?>
                            </a>
                        </span>
                    </td>
                    <td class="col-stats" data-stats="<?php echo $item->getId(); ?>">
                      <span class="mt-xs has-click-event clickable-stat"
                            id="read-percent-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_click').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.click.percentage.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.readpercent', ['%count%' => $item->getClickCount(), '%percentage%'=>$item->getClickPercentage()]); ?>
                            </a>
                        </span>
                    </td>
                    <?php if ($isAdmin || !$notificationemail):?>
                    <td class="col-stats" data-stats="<?php echo $item->getId(); ?>">
                      <span class="mt-xs has-click-event clickable-stat"
                            id="unsubscribe-count-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_unsubscribe').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.unsubscribe.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.unsubscribecount', ['%count%' => $item->getUnsubscribeCount(true), '%percentage%'=>$item->getUnsubscribePercentage()]); ?>
                            </a>
                        </span>
                    </td>
                    <?php endif; ?>
                    <?php if ($isAdmin): //!$notificationemail ||?>
                    <td class="col-stats">
                           <span class="mt-xs has-click-event clickable-stat"
                                 id="bounce-count-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_bounce').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.bounce.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.leadcount', ['%count%' => $item->getBounceCount(true)]); ?>
                            </a>
                        </span>
                    </td>
                    <td class="col-stats">
                       <span class="mt-xs has-click-event clickable-stat"
                             id="spam-count-<?php echo $item->getId(); ?>"  >
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_spam').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.spam.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.leadcount', ['%count%' => $item->getSpamCount(true)]); ?>
                            </a>
                        </span>
                    </td>
                        <td class="col-stats" data-stats="<?php echo $item->getId(); ?>">
                     <span class="mt-xs has-click-event clickable-stat"
                           id="failure-count-<?php echo $item->getId(); ?>">
                            <a href="<?php echo $view['router']->path(
                                'le_contact_index',
                                ['search' => $view['translator']->trans('le.lead.lead.searchcommand.email_failure').':'.$item->getId()]
                            ); ?>" data-toggle="tooltip"
                               title="<?php echo $view['translator']->trans('le.email.stat.failure.tooltip'); ?>">
<!--                                <div class="email-spinner-alignment">-->
<!--                                    <i class="fa fa-spin fa-spinner"></i>-->
<!--                                </div>-->
                                <?php echo $view['translator']->trans('le.email.stat.leadcount', ['%count%' => $item->getFailureCount(true)]); ?>
                            </a>
                        </span>
                        </td>
                    <?php endif; ?>
                    <?php if ($isAdmin):?>
                    <td class=""><?php echo $item->getId(); ?></td>
                    <?php endif; ?>
                    <td>

                        <?php $hasEditAccess = $view['security']->hasEntityAccess($permissions['email:emails:editown'], $permissions['email:emails:editother'], $item->getCreatedBy());
                        $hasDeleteAccess     = $view['security']->hasEntityAccess($permissions['email:emails:deleteown'], $permissions['email:emails:deleteother'], $item->getCreatedBy());
                        $hasCloneAccess      = $permissions['email:emails:create']; ?>
                        <div style="position: relative;" class="fab-list-container">
                            <div class="md-fab-wrapper">
                                <div class="md-fab md-fab-toolbar md-fab-small md-fab-primary" id="mainClass-<?php echo $item->getId(); ?>" style="">
                                    <i class="material-icons" onclick="Le.showActionButtons('<?php echo $item->getId(); ?>')"></i>
                                    <div tabindex="0" class="md-fab-toolbar-actions toolbar-actions-<?php echo $item->getId(); ?>">
                                        <?php if ($hasEditAccess): ?>
                                            <a class="hidden-xs-sm -nospin" title="<?php echo $view['translator']->trans('mautic.core.form.edit'); ?>" href="<?php echo $view['router']->path(!$notificationemail ? 'le_email_campaign_action' : 'le_email_action', ['objectAction' => 'edit', 'objectId' => $item->getId()]); ?>" data-toggle="ajax">
                                                <span><i class="material-icons md-color-white">  </i></span></a>
                                        <?php endif; ?>
                                        <?php if ($hasCloneAccess) : ?>
                                            <a class="hidden-xs" title="<?php echo $view['translator']->trans('mautic.core.form.clone'); ?>" href="<?php echo $view['router']->path(!$notificationemail ? 'le_email_campaign_action' : 'le_email_action', ['objectId' => $item->getId(), 'objectAction' => 'clone']); ?>" data-toggle="ajax" data-uk-tooltip="">
                                                <i class="material-icons md-color-white">  </i> </a>
                                        <?php endif; ?>
                                        <?php if ($hasDeleteAccess):?>
                                            <a data-toggle="confirmation" href="<?php echo $view['router']->path(!$notificationemail ? 'le_email_campaign_action' : 'le_email_action', ['objectAction' => 'delete', 'objectId' => $item->getId()]); ?>" data-message="<?php echo $view->escape($view['translator']->trans(!$notificationemail ? 'mautic.email.form.confirmdelete' : 'mautic.email.notification.form.confirmdelete', ['%name%'=> $item->getName()])); ?>" data-confirm-text="<?php echo $view->escape($view['translator']->trans('mautic.core.form.delete')); ?>" data-confirm-callback="executeAction" title="<?php echo $view['translator']->trans('mautic.core.form.delete'); ?>" data-cancel-text="<?php echo $view->escape($view['translator']->trans('mautic.core.form.cancel')); ?>">
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
    </div>
    <div class="panel-footer">
        <?php echo $view->render(
            'MauticCoreBundle:Helper:pagination.html.php',
            [
                'totalItems' => $totalItems,
                'page'       => $page,
                'limit'      => $limit,
                'baseUrl'    => $view['router']->path($indexRoute),
                'sessionVar' => 'email',
            ]
        ); ?>
    </div>
<?php else: ?>
    <?php echo $view->render('MauticCoreBundle:Helper:noresults.html.php'); ?>
<?php endif; ?>
