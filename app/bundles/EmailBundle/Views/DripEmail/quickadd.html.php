<?php

/*
 * @copyright   2018 LeadsEngage Contributors. All rights reserved
 * @author      LeadsEngage
 *
 * @link        https://leadsengage.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

?>
<p><h4 style="font-size: 13px;margin-top: -32px;margin-bottom: 24px;"><?php echo $view['translator']->trans('le.drip.email.new.add.desc'); ?></h4></p>
<?php echo $view['form']->start($form); ?>
    <div class="row">
    <div class="col-md-12">
        <?php echo $view['form']->row($form['name']); ?>
    </div>
    <div class="col-md-12" style="margin-top: 15px;">
        <?php echo $view['form']->row($form['category']); ?>

    </div>
</div>
<?php echo $view['form']->end($form); ?>
