<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
$view->extend('MauticCoreBundle:Default:content.html.php');
$view['slots']->set('leContent', 'form');
$view['slots']->set('headerTitle', $activeForm->getName());
$custombuttons=[
    [
        'attr' => [
            'data-toggle' => 'ajax',
            'href'        => $view['router']->path(
                'le_form_action',
                ['objectAction' => 'results', 'objectId' => $activeForm->getId()]
            ),
        ],
        'iconClass' => 'fa fa-database',
        'btnText'   => 'mautic.form.form.results',
    ],
];
if (!$activeForm->isSmartForm()) {
    $custombuttons[]= [
        'attr' => [
            'data-toggle' => '',
            'target'      => '_blank',
            'href'        => $view['router']->path(
                'le_form_action',
                ['objectAction' => 'preview', 'objectId' => $activeForm->getId()]
            ),
        ],
        'iconClass' => 'fa fa-camera',
        'btnText'   => 'mautic.form.form.preview',
        'btnClass'  => 'btn btn-default btn-nospin',
    ];
}
$view['slots']->set(
    'actions',
    $view->render(
        'MauticCoreBundle:Helper:page_actions.html.php',
        [
            'item'            => $activeForm,
            'templateButtons' => [
                'edit' => $view['security']->hasEntityAccess(
                    $permissions['form:forms:editown'],
                    $permissions['form:forms:editother'],
                    $activeForm->getCreatedBy()
                ),
                'clone'  => !$activeForm->isSmartForm() && $permissions['form:forms:create'],
                'delete' => $view['security']->hasEntityAccess(
                    $permissions['form:forms:deleteown'],
                    $permissions['form:forms:deleteother'],
                    $activeForm->getCreatedBy()
                ),
                'close' => $view['security']->hasEntityAccess(
                    $permissions['form:forms:viewown'],
                    $permissions['form:forms:viewother'],
                    $activeForm->getCreatedBy()
                ),
            ],
            'routeBase'     => 'form',
            'langVar'       => 'form',
            'customButtons' => $custombuttons,
        ]
    )
);

$view['slots']->set(
    'publishStatus',
    $view->render('MauticCoreBundle:Helper:publishstatus_badge.html.php', ['entity' => $activeForm])
);

$showActions = count($activeFormActions);
$isadmin     =$view['security']->isAdmin();
?>
<!-- start: box layout -->
<div class="box-layout">
    <!-- left section -->
    <div class="col-md-9 bg-white height-auto">
        <div class="bg-auto">
            <!-- form detail header -->
            <div class="pr-md pl-md pt-lg pb-lg">
                <div class="box-layout">
                    <div class="col-xs-10">
                        <div class="text-muted"><?php echo $activeForm->getDescription(); ?></div>
                    </div>
                </div>
            </div>
            <!--/ form detail header -->

            <!-- form detail collapseable -->
            <div class="collapse" id="form-details">
                <div class="pr-md pl-md pb-md">
                    <div class="panel shd-none mb-0">
                        <table class="table table-bordered table-striped mb-0">
                            <tbody>
                            <?php echo $view->render(
                                'MauticCoreBundle:Helper:details.html.php',
                                ['entity' => $activeForm]
                            ); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!--/ form detail collapseable -->
        </div>

        <div class="bg-auto bg-dark-xs">
            <!-- form detail collapseable toggler -->
            <div class="hr-expand nm">
                <span data-toggle="tooltip"
                      title="<?php echo $view['translator']->trans('mautic.form.details.detail'); ?>">
                    <a href="javascript:void(0)" class="arrow text-muted collapsed" data-toggle="collapse"
                       data-target="#form-details"><span class="caret"></span> <?php echo $view['translator']->trans(
                            'mautic.core.details'
                        ); ?></a>
                </span>
            </div>
            <!--/ form detail collapseable toggler -->

            <!-- stats -->
            <div class="pa-md">
                <div class="row">
                    <div class="col-sm-12">
                        <div class="panel">
                            <div class="panel-body box-layout">
                                <div class="col-xs-6 va-m">
                                    <h5 class="text-white dark-md fw-sb mb-xs">
                                        <span class="fa fa-download"></span>
                                        <?php echo $view['translator']->trans('mautic.form.graph.line.submissions'); ?>
                                    </h5>
                                </div>
                                <div class="col-md-8 va-m">
                                    <?php echo $view->render(
                                        'MauticCoreBundle:Helper:graph_dateselect.html.php',
                                        ['dateRangeForm' => $dateRangeForm, 'class' => 'pull-right']
                                    ); ?>
                                </div>
                            </div>
                            <div class="pt-0 pl-15 pb-10 pr-15">
                                <?php echo $view->render(
                                    'MauticCoreBundle:Helper:chart.html.php',
                                    ['chartData' => $stats['submissionsInTime'], 'chartType' => 'line', 'chartHeight' => 500]
                                ); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!--/ stats -->

            <!-- tabs controls -->
            <ul class="nav nav-tabs pr-md pl-md">
                <?php if ($showActions): ?>
                    <li class="active">
                        <a href="#actions-container" role="tab" data-toggle="tab">
                            <?php echo $view['translator']->trans('mautic.form.tab.actions'); ?>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (!$activeForm->isSmartForm()):?>
                    <li class="<?php if (!$showActions) {
                                    echo 'active';
                                } ?>">
                        <a href="#fields-container" role="tab" data-toggle="tab">
                            <?php echo $view['translator']->trans('mautic.form.tab.fields'); ?>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
            <!--/ tabs controls -->
        </div>

        <!-- start: tab-content -->
        <div class="tab-content pa-md">
            <?php if ($showActions): ?>
                <!-- #actions-container -->
                <div class="tab-pane active fade in bdr-w-0" id="actions-container">
                    <?php foreach ($activeFormActions as $group => $groupActions) : ?>
                        <h5 class="fw-sb mb-xs"><?php echo ucfirst($group); ?></h5>
                        <ul class="list-group">
                            <?php /** @var \Mautic\FormBundle\Entity\Action $action */ ?>
                            <?php foreach ($groupActions as $action) : ?>
                                <li class="list-group-item bg-auto bg-light-xs">
                                    <div class="box-layout">
                                        <?php switch ($group) {
                                            // TODO - Better way of doing this
                                            case 'lead':
                                                $icon = 'fa-user';
                                                break;
                                            case 'asset':
                                                $icon = 'fa-cloud-download';
                                                break;
                                            default:
                                                $icon = '';
                                        } ?>
                                        <?php if ($icon != ''): ?>
                                            <div class="col-md-1 va-m">
                                                <h3><span class="fa <?php echo $icon; ?> text-white dark-xs"></span>
                                                </h3>
                                            </div>
                                        <?php endif; ?>
                                        <div class="col-md-7 va-m">
                                            <h5 class="fw-sb text-primary mb-xs"><?php echo $action->getName(); ?></h5>
                                            <h6 class="text-white dark-sm"><?php echo $action->getDescription(); ?></h6>
                                        </div>
                                        <div class="col-md-4 va-m text-right">
                                            <em class="text-white dark-sm">
                                                <?php echo (isset($availableActions[$action->getType()])) ? $view['translator']->trans($availableActions[$action->getType()]['label']) : $action->getType(); ?>
                                            </em>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
                <!--/ #actions-container -->
            <?php endif; ?>
<?php if (!$activeForm->isSmartForm()):?>
    <!-- #fields-container -->
    <div class="tab-pane fade<?php if (!$showActions) {
                                            echo ' active in';
                                        } ?> bdr-w-0" id="fields-container">
        <h5 class="fw-sb mb-xs">Form Field</h5>
        <ul class="list-group mb-xs">
            <?php /** @var \Mautic\FormBundle\Entity\Field $field */
            foreach ($activeFormFields as $field) : ?>
                <li class="list-group-item bg-auto bg-light-xs">
                    <div class="box-layout">
                        <div class="col-md-1 va-m">
                            <?php $requiredTitle = $field->getIsRequired() ? 'mautic.core.required'
                                : 'mautic.core.not_required'; ?>
                            <h3><span class="fa fa-<?php echo $field->getIsRequired() ? 'check'
                                    : 'times'; ?> text-white dark-xs" data-toggle="tooltip"
                                      data-placement="left"
                                      title="<?php echo $view['translator']->trans($requiredTitle); ?>"></span>
                            </h3>
                        </div>
                        <div class="col-md-7 va-m">
                            <h5 class="fw-sb text-primary mb-xs"><?php echo $field->getLabel(); ?></h5>
                            <h6 class="text-white dark-md"><?php echo $view['translator']->trans(
                                    'mautic.form.details.field_type',
                                    ['%type%' => $field->getType()]
                                ); ?></h6>
                        </div>
                        <div class="col-md-4 va-m text-right">
                            <em class="text-white dark-sm"><?php echo $view['translator']->trans(
                                    'mautic.form.details.field_order',
                                    ['%order%' => $field->getOrder()]
                                ); ?></em>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <!--/ #fields-container -->
<?php endif; ?>
        </div>
        <!--/ end: tab-content -->
    </div>
    <!--/ left section -->

    <!-- right section -->
    <div class="col-md-3 bg-white bdr-l height-auto">
        <!-- form HTML -->
        <div class="pa-md">
            <div class="panel bg-info bg-light-lg bdr-w-0 mb-0">
                <div class="panel-body">
                    <h5 class="fw-sb mb-sm"><?php echo $view['translator']->trans(!$activeForm->isSmartForm() ?
                            'mautic.form.form.header.copy' : 'le.smart.form.tracker.header.copy'
                        ); ?></h5>
                    <p class="mb-sm"><?php echo $view['translator']->trans(!$activeForm->isSmartForm() ? 'mautic.form.form.help.landingpages' : 'le.smart.form.help.tracker'); ?></p>

                    <a href="#" class="btn btn-info" data-toggle="modal" style="border-color: #ffffff; "
                       data-target="#modal-automatic-copy"><?php echo $view['translator']->trans(
                            'mautic.form.form.header.automaticcopy'
                        ); ?></a>
                    <?php if ($isadmin): ?>
                        <a href="#" class="btn btn-info" data-toggle="modal"
                        data-target="#modal-manual-copy"><?php echo $view['translator']->trans(
                                'mautic.form.form.header.manualcopy'
                            ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!--/ form HTML -->

        <hr class="hr-w-2" style="width:50%">

        <!--
        we can leverage data from audit_log table
        and build activity feed from it
        -->
        <div class="panel bg-transparent shd-none bdr-rds-0 bdr-w-0 mb-0">

            <!-- recent activity -->
            <?php echo $view->render('MauticCoreBundle:Helper:recentactivity.html.php', ['logs' => $logs]); ?>

        </div>
    </div>
    <!--/ right section -->

    <!-- #modal-automatic-copy -->
    <div class="modal fade" id="modal-automatic-copy">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-sb"><?php echo $view['translator']->trans(
                            'mautic.form.form.header.automaticcopy'
                        ); ?></h5>
                </div>
                <div class="modal-body">
                    <?php if ($activeForm->isSmartForm()):?>
                        <h3 style="font-weight: bold;"><?php echo $view['translator']->trans('le.smart.form.tracker.help.automaticcopy.js'); ?></h3>
                        <p style="margin-top: 10px;"><?php echo $view['translator']->trans('le.smart.form.tracker.help.automaticcopy'); ?></p>
                        <textarea id="javascipt_textarea" class="form-control" readonly>&lt;script type="text/javascript" src="<?php echo $view['router']->url(
                                'le_smart_form_tracker'); ?>"&gt;&lt;/script&gt;</textarea>
                        <a id="javascipt_textarea_atag" onclick="Le.copytoClipboardforms('javascipt_textarea');">
                            <i aria-hidden="true" class="fa fa-clipboard"></i>
                            <?php echo $view['translator']->trans(
                                'leadsengage.subs.clicktocopy'
                            ); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$activeForm->isSmartForm()):?>
                        <p><?php echo $view['translator']->trans('mautic.form.form.help.automaticcopy'); ?></p>
                        <h3><?php echo $view['translator']->trans('mautic.form.form.help.automaticcopy.js'); ?></h3>
                        <textarea id="javascipt_textarea" class="form-control" readonly>&lt;script type="text/javascript" src="<?php echo $view['router']->url(
                                'le_form_generateform',
                                ['id' => $activeForm->getId()]
                            ); ?>"&gt;&lt;/script&gt;</textarea>
                        <a id="javascipt_textarea_atag" onclick="Le.copytoClipboardforms('javascipt_textarea');">
                            <i aria-hidden="true" class="fa fa-clipboard"></i>
                            <?php echo $view['translator']->trans(
                                'leadsengage.subs.clicktocopy'
                            ); ?>
                        </a>
                        <h3 class="pt-lg"><?php echo $view['translator']->trans(
                                'mautic.form.form.help.automaticcopy.iframe'
                            ); ?></h3>
                        <textarea id="iframe_textarea" class="form-control" readonly onclick="Le.copytoClipboardforms(this);">&lt;iframe style="border: 0px solid;" src="<?php echo $view['router']->url(
                                'le_form_preview',
                                ['id' => $activeForm->getId()]
                            ); ?>" width="350" height="350"&gt;&lt;p&gt;Your browser does not support iframes.&lt;/p&gt;&lt;/iframe&gt;</textarea>
                        <a id="iframe_textarea_atag" onclick="Le.copytoClipboardforms('iframe_textarea');"><i aria-hidden="true" class="fa fa-clipboard"></i>
                            <?php echo $view['translator']->trans(
                                'leadsengage.subs.clicktocopy'
                            ); ?></a>
                        <br>
                        <br>
                        <i><?php echo $view['translator']->trans('mautic.form.form.help.automaticcopy.iframe.note'); ?></i>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $view['translator']->trans(
                            'mautic.core.close'
                        ); ?></button>
                </div>
            </div>
        </div>
    </div>
    <!--/ #modal-automatic-copy -->

    <!-- #modal-manual-copy -->
    <div class="modal fade" id="modal-manual-copy">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-sb"><?php echo $view['translator']->trans(
                            'mautic.form.form.header.manualcopy'
                        ); ?></h5>
                </div>
                <div class="panel-body">
                    <p><?php echo $view['translator']->trans('mautic.form.form.help.manualcopy'); ?></p>

                    <h4 class="mt-sm mb-sm"><?php echo $view['translator']->trans(
                            'mautic.form.form.help.manualcopy.script'
                        ); ?></h4>
                    <textarea class="form-html form-control" readonly
                              onclick="this.setSelectionRange(0, this.value.length);"><?php echo $formScript; ?></textarea>

                    <h4 class="mt-sm mb-sm"><?php echo $view['translator']->trans(
                            'mautic.form.form.help.manualcopy.body'
                        ); ?></h4>
                    <textarea class="form-html form-control" readonly
                              onclick="this.setSelectionRange(0, this.value.length);"><?php echo $formContent; ?></textarea>
                </div>
                <div class="panel-footer text-right">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $view['translator']->trans(
                            'mautic.core.close'
                        ); ?></button>
                </div>
            </div>
        </div>
    </div>
    <!--/ #modal-manual-copy -->
</div>
<!--/ end: box layout -->

<input type="hidden" name="entityId" id="entityId" value="<?php echo $view->escape($activeForm->getId()); ?>"/>
