<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
$formName = '_'.$form->generateFormName().(isset($suffix) ? $suffix : '');
if (!isset($fields)) {
    $fields = $form->getFields();
}
$pageCount = 1;

if (!isset($inBuilder)) {
    $inBuilder = false;
}

if (!isset($action)) {
    $action = $view['router']->url('le_form_postresults', ['formId' => $form->getId()]);
}

if (!isset($theme)) {
    $theme = '';
}

if (!isset($contactFields)) {
    $contactFields = $companyFields = [];
}

if (!isset($style)) {
    $style = '';
}

if (!isset($isAjax)) {
    $isAjax = true;
}
?>

<?php echo $style; ?>

<div id="leform_wrapper<?php echo $formName ?>" class="leform_wrapper">
    <form autocomplete="false" role="form" method="post" action="<?php echo  $action; ?>" id="leform<?php echo $formName ?>" <?php if ($isAjax): ?> data-le-form="<?php echo ltrim($formName, '_') ?>"<?php endif; ?> enctype="multipart/form-data">
        <div class="leform-error" id="leform<?php echo $formName ?>_error"></div>
        <div class="leform-message" id="leform<?php echo $formName ?>_message"></div>
        <div class="leform-innerform">

            <?php
            /** @var \Mautic\FormBundle\Entity\Field $f */
            foreach ($fields as $fieldId => $f):
                if (isset($formPages['open'][$fieldId])):
                    // Start a new page
                    $lastFieldAttribute = ($lastFormPage === $fieldId) ? ' data-le-form-pagebreak-lastpage="true"' : '';
                    echo "\n          <div class=\"leform-page-wrapper leform-page-$pageCount\" data-le-form-page=\"$pageCount\"$lastFieldAttribute>\n";
                endif;

                if (!isset($submissions) || $f->showForContact($submissions, $lead, $form)):
                    if ($f->isCustom()):
                        if (!isset($fieldSettings[$f->getType()])):
                            continue;
                        endif;
                        $params = $fieldSettings[$f->getType()];
                        $f->setCustomParameters($params);

                        $template = $params['template'];
                    else:
                        if (!$form->getGDPRPublished() && $f->getLeadField() == 'eu_gdpr_consent') {
                            $f->setType('hidden');
                            $f->setDefaultValue('UnKnown');
                        }
                        if (!$f->getShowWhenValueExists() && $f->getLeadField() && $f->getIsAutoFill() && $lead && !empty($lead->getFieldValue($f->getLeadField()))) {
                            $f->setType('hidden');
                        }
                        $template = 'MauticFormBundle:Field:'.$f->getType().'.html.php';
                    endif;
                    echo $view->render(
                        $theme.$template,
                        [
                            'field'          => $f->convertToArray(),
                            'id'             => $f->getAlias(),
                            'formName'       => $formName,
                            'fieldPage'      => ($pageCount - 1), // current page,
                            'contactFields'  => $contactFields,
                            'companyFields'  => $companyFields,
                            'inBuilder'      => $inBuilder,
                            'gcaptchasitekey'=> !$inBuilder && $f->isGCaptchaType() ? $gcaptchasitekey : '',
                        ]
                    );
                endif;

                if (isset($formPages) && isset($formPages['close'][$fieldId])):
                    // Close the page
                    echo "\n            </div>\n";
                    ++$pageCount;
                endif;

            endforeach;
            ?>
        </div>

        <input type="hidden" name="leform[formId]" id="leform<?php echo $formName ?>_id" value="<?php echo $view->escape($form->getId()); ?>"/>
        <input type="hidden" name="leform[return]" id="leform<?php echo $formName ?>_return" value=""/>
        <input type="hidden" name="leform[formName]" id="leform<?php echo $formName ?>_name" value="<?php echo $view->escape(ltrim($formName, '_')); ?>"/>

        <?php echo (isset($formExtra)) ? $formExtra : ''; ?>
</form>
</div>
