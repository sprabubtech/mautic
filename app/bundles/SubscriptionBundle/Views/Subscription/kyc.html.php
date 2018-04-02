<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
$header     = $view['translator']->trans('leadsengage.kyc.profile_completion');
$style      = '';
$videostyle = '';
if ($showSetup && $showVideo) {
    $style      = 'width:80%;display:block';
    $videostyle = 'width:55%;display:none;';
} elseif (!$showSetup && $showVideo) {
    $style     = 'width:80%;display:none';
    $videostyle='width:55%;display:block';
}
?>
<div class="type-modal-backdrop" style="width: 100%; height: 100%; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: #000000; opacity: 0.9; z-index: 1500"></div>

<div class="modal fade in " style="display: block;z-index: 1500;">
    <div class="modal-dialog steps" style="<?php echo $style; ?>" role="document">
        <?php if ($showSetup): ?>
        <form class="form-group login-form" name="terms_condition" data-toggle="ajax" role="form" onsubmit="return Mautic.SendOTPConnection();" method="post">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">
                    <?php echo $view['translator']->trans($header); ?>
                </h4>
            </div>

            <div class="modal-body form-select-modal">
                <div>
                    <div role="tabpanel" class="tab-pane fade in active bdr-w-0">
                    <div class="pt-md pr-md pl-md pb-md">
                    <div class="panel-body" style="width:100%;">
                    <div class="row">
                    <div class="row">
                        <div class="col-md-6" id ="user_Firstname">
                            <?php echo $view['form']->label($userform['firstName']); ?>
                            <?php echo $view['form']->widget($userform['firstName']); ?>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-6" id = "user_Lastname">
                            <?php echo $view['form']->label($userform['lastName']); ?>
                            <?php echo $view['form']->widget($userform['lastName']); ?>
                            <div class="help-block"></div>
                        </div>
                    </div>
                    <br>
                    <div class="row">
                        <div class="col-md-6" id ="account_Mobile">
                            <?php echo $view['form']->label($form['phonenumber']); ?>
                            <?php echo $view['form']->widget($form['phonenumber']); ?>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-6" id = "user_Email">
                            <?php echo $view['form']->label($userform['email']); ?>
                            <?php echo $view['form']->widget(
                                $userform['email'],
                                ['attr' => ['style' => 'pointer-events: none;background-color: #ebedf0;opacity: 1;']]
                            ); ?>
                            <div class="help-block"></div>
                        </div>
                    </div>
                        <br>
                    <div class="row">
                        <div class="col-md-6" id = "billing_Company">
                            <?php echo $view['form']->label($billform['companyname']); ?>
                            <?php echo $view['form']->widget($billform['companyname']); ?>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-6" id = "account_Website">
                            <?php echo $view['form']->label($form['website']); ?>
                            <?php echo $view['form']->widget($form['website'], ['attr' => ['placeholder' => 'http://']]); ?>
                            <div class="help-block"></div>
                        </div>
                    </div>
                    <br>
                    <div class="row">
                        <div class="col-md-12" id="billing_Address">
                            <?php echo $view['form']->label($billform['companyaddress']); ?>
                            <?php echo $view['form']->widget($billform['companyaddress']); ?>
                            <label>Will be Used in Email Footer for CAN-SPAM Compliance</label>
                            <div class="help-block"></div>
                        </div>
                    </div>
                    <br>
                    <div class="row">
                        <div class="col-md-6" id ="billing_City">
                            <?php echo $view['form']->label($billform['city']); ?>
                            <?php echo $view['form']->widget($billform['city']); ?>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-6" id="billing_state">
                            <?php echo $view['form']->label($billform['state']); ?>
                            <?php echo $view['form']->widget($billform['state']); ?>
                            <div class="help-block"></div>
                        </div>
                    </div>
                    <br>
                    <div class="row">
                        <div class="col-md-6" id = "billing_Postal">
                            <?php echo $view['form']->label($billform['postalcode']); ?>
                            <?php echo $view['form']->widget($billform['postalcode']); ?>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-6"  id="billing_country">
                            <?php echo $view['form']->label($billform['country']); ?>
                            <?php echo $view['form']->widget($billform['country']); ?>
                            <div class="help-block"></div>
                        </div>
                    </div>
                    <br>
                    <div class="row">
                        <div class="col-md-6"  id="account_timezone">
                            <?php echo $view['form']->label($form['timezone']); ?>
                            <?php echo $view['form']->widget($form['timezone']); ?>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-6" id="billing_GST">
                            <?php echo $view['form']->label($billform['gstnumber']); ?>
                            <?php echo $view['form']->widget($billform['gstnumber']); ?>
                            <div class="help-block"></div>
                        </div>
                    </div>
                        <br>
                        <div class="col-md-12" id="condition_Agree">
                            <label class="control control-checkbox">
                            <?php echo $view['translator']->trans('le.kyc.conditionAgree'); ?>
                            <input type="checkbox" id="conditionAgree" name="kycinfo[leadsengage_conditionsagree]"/>
                            <div class="control_indicator"></div>

                            </label>
                            <div class="help-block"></div>
                        </div>
                        <div class="col-md-12" id="spam_Agree">
                            <label class="control control-checkbox">
                            <?php echo $view['translator']->trans('le.kyc.spamAgree'); ?>
                            <input type="checkbox" id ="spamAgree" name="kycinfo[leadsengage_spamcondition]"/>
                            <div class="control_indicator"></div>

                            </label>
                            <div class="help-block"></div>
                        </div>
                        <br>
                        <br>
                        <br>
                        <br>
                        <div class="modal-footer">
                            <div class="button_container" id="kycSubmit">
                                <a class="signup_next exit_logout" style="padding:12px;" href="<?php echo $view['router']->path('mautic_user_logout'); ?>" class="exitlogout" ><span aria-hidden="true">Exit and Logout</span></a>
                                <button class="signup_next" type="submit" value="Next"><?php echo $view['translator']->trans('leadsengage.kyc.submit'); ?></button>

                            <span class="fa fa-spinner fa-spin hide"></span>
                            </div>
                        </div>
                    </div>
                    </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
        </form>
        <?php endif; ?>
    </div>
    <div class="modal-dialog otp_verifications" style="display:none;" role="document">
        <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" data-ng-click="cancel()"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">Validate your account</h4>
        </div>

        <form method="post" name="smsVerify" data-ng-submit="verifySms(smsVerify)" novalidate>

            <div class="modal-body">

                <!-- begin .row -->
                <div class="row">
                    <div class="col-md-10 col-md-offset-1 text-center">
                        <p>A code was just sent to your mobile phone : <b>{{form.profile_office_telephone}}</b></p>
                        <p>Please enter the code that was sent to your phone in this field.</p>
                    </div>
                    <div class="form-group col-md-6 col-md-offset-3 text-center" data-ng-class="{'has-error':smsVerify.sms_code.$invalid && smsCodeSubmitted}">
                        <input class="form-control" required type="text" data-ng-model="sms_code" name="sms_code" id="sms_code" />
                    </div>
                    <div class="col-md-10 col-md-offset-1 spacer-top-xs text-muted">
                        <small><b>In an effort to protect our users from abuse</b>, we ask users to prove they are not a robot before they are able to create an account. Having this additional confirmation via phone is an effective way to keep spammers from abusing our system. Thanks for your help!</small>
                    </div>
                </div>
                <!-- end .row -->

            </div>

            <div class="modal-footer">
                <a id="verify" onclick="Mautic.LoadKYCDetails();" type="submit" class="btn btn-primary"><i data-ng-show="smsLoader" class="fa fa-circle-o-notch" data-ng-class="{'fa-spin':smsLoader}"></i> Back</a>
                <button id="send_sms" type="button" data-ng-click="send_sms()" class="btn btn-default disabled" disabled="true"><i class="fa fa-repeat"></i> Resend code</button>
                <button id="verify"  type="submit" class="btn btn-primary"><i data-ng-show="smsLoader" class="fa fa-circle-o-notch" data-ng-class="{'fa-spin':smsLoader}"></i> Validate</button>
            </div>

        </form>

        </div>
    </div>
    <div class="modal-dialog video_page" style="<?php echo $videostyle; ?>" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <a href="javascript: void(0);" onclick="Mautic.closeModalAndRedirect('.<?php echo $typePrefix; ?>-type-modal', '<?php echo $view['router']->path('mautic_dashboard_index'); ?>');" class="dont_show_again close_button" ><span><i class="fa fa-close"></i><span style="padding:4px;">Close</span></span></a>
                <a href="javascript: void(0);" onclick="Mautic.closeModalAndRedirect('.<?php echo $typePrefix; ?>-type-modal', '<?php echo $view['router']->path('mautic_dashboard_index', ['login' => 'dont_show_again']); ?>');" class="dont_show_again" ><span><i class="fa fa-eye-slash"></i><span style="padding:4px;"><?php echo $view['translator']->trans('leadsengage.kyc.dont_show'); ?></span></span></a>
                <h4 class="modal-title">
                    <?php echo $view['translator']->trans('leadsengage.kyc.video_header'); ?>
                </h4>
            </div>
            <div class="modal-body form-select-modal">
                <iframe width="100%" height="450px"
                        src="<?php echo $videoURL; ?>">
                </iframe>
            </div>
        </div>
    </div>
    </div>
</div>
