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
$view['slots']->set('leContent', 'accountinfo');
$view['slots']->set('headerTitle', $view['translator']->trans('leadsengage.accountinfo.header.title'));
$hidepanel  =$view['security']->isAdmin() ? '' : "style='display: none;'";
?>
<!-- start: box layout -->
<div class="box-layout">
    <!-- step container -->
    <?php echo $view->render('MauticSubscriptionBundle:AccountInfo:steps.html.php', [
        'step'                => 'accountinfo',
        'typePrefix'          => $typePrefix,
        'actionRoute'         => $actionRoute,
        'planType'            => $planType,
        'planName'            => $planName,
        'isEmailVerified'     => $isEmailVerified,
    ]); ?>
   <!-- container -->
    <div class="col-md-9 bg-auto height-auto accountinfo">

        <!-- Tab panes -->
        <div class="">

            <?php echo $view['form']->start($form); ?>
            <div role="tabpanel" class="tab-pane fade in active bdr-w-0" id="accountinfo">
                <div class="pt-md pr-md pl-md pb-md">
                    <div class="panel panel-primary">
                    <div class="panel-heading">
                        <h3 class="panel-title"><?php echo $view['translator']->trans('leadsengage.accountinfo.title'); ?></h3>
                    </div>
                    <div class="panel-body">
                        <div class="row">
                            <div class="row form-group col-xs-12" >
                            <div class="col-md-6 <?php echo (count($form['accountname']->vars['errors'])) ? ' has-error' : ''; ?>">
                                <?php echo $view['form']->label($form['accountname']); ?>
                                <?php echo $view['form']->widget($form['accountname']); ?>
                                <?php echo $view['form']->errors($form['accountname']); ?>
                            </div>
                            <div class="col-md-6 <?php echo (count($form['domainname']->vars['errors'])) ? ' has-error' : ''; ?>">
                                <?php echo $view['form']->label($form['domainname']); ?>
                                <?php echo $view['form']->widget($form['domainname']); ?>
                                <?php echo $view['form']->errors($form['domainname']); ?>
                            </div>
                            </div>
                            <div class="row form-group col-xs-12">
                            <div class="col-md-6<?php echo (count($form['email']->vars['errors'])) ? ' has-error' : ''; ?>">
                                <?php echo $view['form']->label($form['email']); ?>
                                <?php echo $view['form']->widget($form['email']); ?>
                                <?php echo $view['form']->errors($form['email']); ?>
                            </div>
                            <div class="col-md-6 <?php echo (count($form['phonenumber']->vars['errors'])) ? ' has-error' : ''; ?>">
                                <?php echo $view['form']->label($form['phonenumber']); ?>
                                <?php echo $view['form']->widget($form['phonenumber']); ?>
                                <?php echo $view['form']->errors($form['phonenumber']); ?>
                            </div>
                            </div>
                            <div class="row form-group col-xs-12">
                            <div class="col-md-6 <?php echo (count($form['timezone']->vars['errors'])) ? ' has-error' : ''; ?>">
                                <?php echo $view['form']->label($form['timezone']); ?>
                                <?php echo $view['form']->widget($form['timezone']); ?>
                                <?php echo $view['form']->errors($form['timezone']); ?>
                            </div>
                            <div class="col-md-6 <?php echo (count($form['website']->vars['errors'])) ? ' has-error' : ''; ?>">
                                <?php echo $view['form']->label($form['website']); ?>
                                <?php echo $view['form']->widget($form['website']); ?>
                                <?php echo $view['form']->errors($form['website']); ?>
                            </div>
                            </div>
                            <div class="row form-group col-xs-12">
                                <div class="col-md-6">
                                </div>
                            <div class="col-md-6 hide">
                                <?php echo $view['form']->label($form['needpoweredby']); ?>
                                <?php echo $view['form']->widget($form['needpoweredby']); ?>
                            </div>
                            </div>
                            <div class="col-md-6 hide"><div class="row"><div class="form-group col-xs-12 ">
                                        <?php echo $view['form']->label($form['accountid']); ?>
                                        <?php echo $view['form']->widget($form['accountid']); ?>
                            </div></div></div>
                            <div class="col-md-6" <?php echo $hidepanel ?>><div class="row"><div class="form-group col-xs-12 ">
                                        <?php echo $view['form']->label($form['currencysymbol']); ?>
                                        <?php echo $view['form']->widget($form['currencysymbol']); ?>
                            </div></div></div>
                        </div>
                    </div>
                </div>
                    <br>
                    <br>
                    <br>
                </div>
            </div>
            <?php echo $view['form']->end($form); ?>
        </div>
    </div>
</div>
