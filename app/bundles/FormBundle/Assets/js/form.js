//FormBundle
Le.formOnLoad = function (container) {
    if (mQuery(container + ' #list-search').length) {
        Le.activateSearchAutocomplete('list-search', 'form.form');
    }
    var bodyOverflow = {};

    mQuery('select.form-builder-new-component').change(function (e) {
        mQuery(this).find('option:selected');
        Le.ajaxifyModal(mQuery(this).find('option:selected'));
        // Reset the dropdown
        mQuery(this).val('');
        mQuery(this).trigger('chosen:updated');
    });


    if (mQuery('#leforms_fields')) {
        //make the fields sortable
        mQuery('#leforms_fields').sortable({
            items: '.panel',
            cancel: '',
            helper: function(e, ui) {
                ui.children().each(function() {
                    mQuery(this).width(mQuery(this).width());
                });

                // Fix body overflow that messes sortable up
                bodyOverflow.overflowX = mQuery('body').css('overflow-x');
                bodyOverflow.overflowY = mQuery('body').css('overflow-y');
                mQuery('body').css({
                    overflowX: 'visible',
                    overflowY: 'visible'
                });

                return ui;
            },
            scroll: true,
            axis: 'y',
            containment: '#leforms_fields .drop-here',
            stop: function(e, ui) {
                // Restore original overflow
                mQuery('body').css(bodyOverflow);
                mQuery(ui.item).attr('style', '');

                mQuery.ajax({
                    type: "POST",
                    url: leAjaxUrl + "?action=form:reorderFields",
                    data: mQuery('#leforms_fields').sortable("serialize", {attribute: 'data-sortable-id'}) + "&formId=" + mQuery('#leform_sessionId').val()
                });
            }
        });

        Le.initFormFieldButtons();
        Le.removeActionButtons();
    }

    mQuery("#ui-tab-header1").addClass('ui-tabs-selected ui-state-active');

    mQuery('.next-tab, .prev-tab, .ui-state-default').click(function() {
        mQuery('#Form_Name').removeClass('has-success has-error');
        mQuery('#Form_post_action').removeClass('has-success has-error');
        mQuery('#Form_Name .help-block').addClass('hide').html("");
        mQuery('#Form_post_action .help-block').addClass('hide').html("");
        if(mQuery('#leform_name').val() == "" && mQuery('#leform_postActionProperty').val() == ""){
            if(mQuery('.check_required').hasClass('required'))
            {
                mQuery('#Form_Name').removeClass('has-success has-error').addClass('has-error');
                mQuery('#Form_Name .custom-help').removeClass('hide').html("Name can't be empty.");
                mQuery('#Form_post_action').removeClass('has-success has-error').addClass('has-error');
                mQuery('#Form_post_action .custom-help').removeClass('hide').html("Redirect URL/Message can't be empty ");

            }else {
                mQuery('#Form_Name').removeClass('has-success has-error').addClass('has-error');
                mQuery('#Form_Name .custom-help').removeClass('hide').html("Name can't be empty.");
            }

            return;
        }
        else if(mQuery('#leform_name').val() == "") {
            mQuery('#Form_Name').removeClass('has-success has-error').addClass('has-error');
            mQuery('#Form_Name .custom-help').removeClass('hide').html("Name can't be empty.");
            return;
        } else if (mQuery('#leform_postActionProperty').val() == "" && mQuery('.check_required').hasClass('required')){
            mQuery('#Form_post_action').removeClass('has-success has-error').addClass('has-error');
            mQuery('#Form_post_action .custom-help').removeClass('hide').html("Redirect URL/Message can't be empty");
            return;
        }
        var selectrel = mQuery(this).attr("rel");
        mQuery(".ui-tabs-panel").addClass('ui-tabs-hide');
        mQuery("#fragment-"+selectrel).removeClass('ui-tabs-hide');
        mQuery(".ui-state-default").removeClass('ui-tabs-selected ui-state-active');
        mQuery("#ui-tab-header"+selectrel).addClass('ui-tabs-selected ui-state-active');
    });

    if (mQuery('#leforms_actions')) {
        //make the fields sortable
        mQuery('#leforms_actions').sortable({
            items: '.panel',
            cancel: '',
            helper: function(e, ui) {
                ui.children().each(function() {
                    mQuery(this).width(mQuery(this).width());
                });

                // Fix body overflow that messes sortable up
                bodyOverflow.overflowX = mQuery('body').css('overflow-x');
                bodyOverflow.overflowY = mQuery('body').css('overflow-y');
                mQuery('body').css({
                    overflowX: 'visible',
                    overflowY: 'visible'
                });

                return ui;
            },
            scroll: true,
            axis: 'y',
            containment: '#leforms_actions .drop-here',
            stop: function(e, ui) {
                // Restore original overflow
                mQuery('body').css(bodyOverflow);
                mQuery(ui.item).attr('style', '');

                mQuery.ajax({
                    type: "POST",
                    url: leAjaxUrl + "?action=form:reorderActions",
                    data: mQuery('#leforms_actions').sortable("serialize") + "&formId=" + mQuery('#leform_sessionId').val()
                });
            }
        });

        mQuery('#leforms_actions .leform-row').on('dblclick.leformactions', function(event) {
            event.preventDefault();
            mQuery(this).find('.btn-edit').first().click();
        });
    }

    if (mQuery('leform_formType').length && mQuery('#leform_formType').val() == '') {
        //mQuery('body').addClass('noscroll');
    }

    Le.initHideItemButton('#leforms_fields');
    Le.initHideItemButton('#leforms_actions');
    if(mQuery('#Form_post_action').hasClass('has-error')){
        mQuery('.check_required').addClass('required');
    }
};

Le.setBtnBackgroundColor = function () {
    var selectedbgcolor =  mQuery('#formfield_btnbgcolor').val() ;
    var input = mQuery('#formfield_inputAttributes').val();

     if (input.indexOf('style=') == -1) {
         var value =input+" style='background-color:#"+selectedbgcolor+";color:#ffffff;'";
         mQuery('#formfield_inputAttributes').val(value);
      }else {
         var fields = input.split('background-color');
         var sec = fields[1].substr(9);
         var value = fields[0]+'background-color:#'+selectedbgcolor+';'+sec;
         mQuery('#formfield_inputAttributes').val(value);
     }
}
Le.setBtnTextColor = function () {
    var selectedtxtcolor =  mQuery('#formfield_btntxtcolor').val() ;
    var input = mQuery('#formfield_inputAttributes').val();

    if (input.indexOf('style=') == -1) {
        var value =input+" style='background-color:#ff9900;color:#"+selectedtxtcolor+";'";
        mQuery('#formfield_inputAttributes').val(value);
    }else{
        var fields = input.split(';color');
        var sec = fields[1].substr(9);
        var value = fields[0]+';color:#'+selectedtxtcolor+';'+sec;
        mQuery('#formfield_inputAttributes').val(value);
    }
}
Le.updateFormFields = function () {
    Le.activateLabelLoadingIndicator('campaignevent_properties_field');

    var formId = mQuery('#campaignevent_properties_form').val();
    Le.ajaxActionRequest('form:updateFormFields', {'formId': formId}, function(response) {
        if (response.fields) {
            var select = mQuery('#campaignevent_properties_field');
            select.find('option').remove();
            var fieldOptions = {};
            mQuery.each(response.fields, function(key, field) {
                var option = mQuery('<option></option>')
                    .attr('value', field.alias)
                    .text(field.label);
                select.append(option);
                fieldOptions[field.alias] = field.options;
            });
            select.attr('data-field-options', JSON.stringify(fieldOptions));
            select.trigger('chosen:updated');
            Le.updateFormFieldValues(select);
        }
        Le.removeLabelLoadingIndicator();
    });
};

Le.updateFormFieldValues = function (field) {
    field = mQuery(field);
    var fieldValue = field.val();
    var options = jQuery.parseJSON(field.attr('data-field-options'));
    var valueField = mQuery('#campaignevent_properties_value');
    var valueFieldAttrs = {
        'class': valueField.attr('class'),
        'id': valueField.attr('id'),
        'name': valueField.attr('name'),
        'autocomplete': valueField.attr('autocomplete'),
        'value': valueField.attr('value')
    };

    if (typeof options[fieldValue] !== 'undefined' && !mQuery.isEmptyObject(options[fieldValue])) {
        var newValueField = mQuery('<select/>')
            .attr('class', valueFieldAttrs['class'])
            .attr('id', valueFieldAttrs['id'])
            .attr('name', valueFieldAttrs['name'])
            .attr('autocomplete', valueFieldAttrs['autocomplete'])
            .attr('value', valueFieldAttrs['value']);
        mQuery.each(options[fieldValue], function(key, optionVal) {
            var option = mQuery("<option></option>")
                .attr('value', optionVal)
                .text(optionVal);
            newValueField.append(option);
        });
        valueField.replaceWith(newValueField);
    } else {
        var newValueField = mQuery('<input/>')
            .attr('type', 'text')
            .attr('class', valueFieldAttrs['class'])
            .attr('id', valueFieldAttrs['id'])
            .attr('name', valueFieldAttrs['name'])
            .attr('autocomplete', valueFieldAttrs['autocomplete'])
            .attr('value', valueFieldAttrs['value']);
        valueField.replaceWith(newValueField);
    }
};

Le.formFieldOnLoad = function (container, response) {
    //new field created so append it to the form
    if (response.fieldHtml) {
        var newHtml = response.fieldHtml;
        var fieldId = '#leform_' + response.fieldId;
        var fieldContainer = mQuery(fieldId).closest('.form-field-wrapper');

        if (mQuery(fieldId).length) {
            //replace content
            mQuery(fieldContainer).replaceWith(newHtml);
            var newField = false;
        } else {
            //append content
            var panel = mQuery('#leforms_fields .leform-button-wrapper').closest('.form-field-wrapper');
            panel.before(newHtml);
            var newField = true;
        }

        // Get the updated element
        var fieldContainer = mQuery(fieldId).closest('.form-field-wrapper');

        //activate new stuff
        mQuery(fieldContainer).find("[data-toggle='ajax']").click(function (event) {
            event.preventDefault();
            return Le.ajaxifyLink(this, event);
        });

        //initialize tooltips
        mQuery(fieldContainer).find("*[data-toggle='tooltip']").tooltip({html: true});

        //initialize ajax'd modals
        mQuery(fieldContainer).find("[data-toggle='ajaxmodal']").on('click.ajaxmodal', function (event) {
            event.preventDefault();
            Le.ajaxifyModal(this, event);
        });

        Le.initFormFieldButtons(fieldContainer);
        Le.initHideItemButton(fieldContainer);

        //show fields panel
        if (!mQuery('#fields-panel').hasClass('in')) {
            mQuery('a[href="#fields-panel"]').trigger('click');
        }

        if (newField) {
            mQuery('.bundle-main-inner-wrapper').scrollTop(mQuery('.bundle-main-inner-wrapper').height());
        }

        if (mQuery('#form-field-placeholder').length) {
            //mQuery('#form-field-placeholder').remove();
        }
    }

    var bgcolor = mQuery('#leform_input_submit').css('background-color');
    var txtcolor = mQuery('#leform_input_submit').css('color');
    var $iconbg =   Le.getBgColorHex(bgcolor);
    var $icontxt =   Le.getBgColorHex(txtcolor);
    mQuery('#formfield_btnbgcolor').minicolors('value',$iconbg);
    mQuery('#formfield_btntxtcolor').minicolors('value',$icontxt);

};

Le.getBgColorHex = function (color){
    var hex;
    if(color.indexOf('#')>-1){
        //for IE
        hex = color;
    } else {
        var rgb = color.match(/\d+/g);
        hex = ('0' + parseInt(rgb[0], 10).toString(16)).slice(-2) + ('0' + parseInt(rgb[1], 10).toString(16)).slice(-2) + ('0' + parseInt(rgb[2], 10).toString(16)).slice(-2);
    }
    return hex;
}

Le.initFormFieldButtons = function (container) {
    if (typeof container == 'undefined') {
        mQuery('#leforms_fields .leform-row').off(".leformfields");
        var container = '#leforms_fields';
    }

    mQuery(container).find('.leform-row').on('dblclick.leformfields', function(event) {
        event.preventDefault();
        mQuery(this).closest('.form-field-wrapper').find('.btn-edit').first().click();
    });
};

Le.formActionOnLoad = function (container, response) {
    //new action created so append it to the form
    if (response.actionHtml) {
        var newHtml = response.actionHtml;
        var actionId = '#leform_action_' + response.actionId;
        if (mQuery(actionId).length) {
            //replace content
            mQuery(actionId).replaceWith(newHtml);
            var newField = false;
        } else {
            //append content
            mQuery(newHtml).appendTo('#leforms_actions');
            var newField = true;
        }
        //activate new stuff
        mQuery(actionId + " [data-toggle='ajax']").click(function (event) {
            event.preventDefault();
            return Le.ajaxifyLink(this, event);
        });
        //initialize tooltips
        mQuery(actionId + " *[data-toggle='tooltip']").tooltip({html: true});

        //initialize ajax'd modals
        mQuery(actionId + " [data-toggle='ajaxmodal']").on('click.ajaxmodal', function (event) {
            event.preventDefault();

            Le.ajaxifyModal(this, event);
        });

        Le.initHideItemButton(actionId);

        mQuery('#leforms_actions .leform-row').off(".leform");
        mQuery('#leforms_actions .leform-row').on('dblclick.leformactions', function(event) {
            event.preventDefault();
            mQuery(this).find('.btn-edit').first().click();
        });

        //show actions panel
        if (!mQuery('#actions-panel').hasClass('in')) {
            mQuery('a[href="#actions-panel"]').trigger('click');
        }

        if (newField) {
            mQuery('.bundle-main-inner-wrapper').scrollTop(mQuery('.bundle-main-inner-wrapper').height());
        }

        if (mQuery('#form-action-placeholder').length) {
            //mQuery('#form-action-placeholder').remove();
        }
    }
};

Le.initHideItemButton = function(container) {
    mQuery(container).find('[data-hide-panel]').click(function(e) {
        e.preventDefault();
        mQuery(this).closest('.panel').hide('fast');
    });
}

Le.onPostSubmitActionChange = function(value) {
    mQuery('#Form_post_action .custom-help').html("");
    if (value == 'return') {
        //remove required class
        mQuery('#leform_postActionProperty').attr('type','text');
        mQuery('#leform_postActionProperty').prev().removeClass('required');
        mQuery('#Form_post_action').addClass('hide');
        mQuery('#leform_postActionProperty').val('');
    } else {
        if(value == 'redirect'){
            mQuery('#leform_postActionProperty').attr('type','url');
            mQuery('#Form_post_action').removeClass('hide');
        } else {
            mQuery('#leform_postActionProperty').attr('type','text');
            mQuery('#Form_post_action').removeClass('hide');
        }
        mQuery('#leform_postActionProperty').prev().addClass('required');
    }

    mQuery('#leform_postActionProperty').next().html('');
    mQuery('#leform_postActionProperty').parent().removeClass('has-error');
};

Le.selectFormType = function(formType) {
    if (formType == 'standalone') {
        mQuery("#form_template_campaign").removeClass('hide').addClass('hide');
        mQuery('option.action-standalone-only').removeClass('hide');
        mQuery('.page-header h3').text(leLang.newStandaloneForm);
    } else {
        mQuery("#form_template_standalone").removeClass('hide').addClass('hide');
        mQuery('option.action-standalone-only').addClass('hide');
        mQuery('.page-header h3').text(leLang.newCampaignForm);
    }

    mQuery('.available-actions select').trigger('chosen:updated');

    mQuery('#leform_formType').val(formType);

    mQuery('body').removeClass('noscroll');

    mQuery('.form-type-modal').remove();
    mQuery('.form-type-modal-backdrop').remove();
};

Le.openNewFormAction = function(url){
    var formtype = mQuery('#leform_formType').val();
    url = url + "_" + formtype;
    window.location.href = url;
};

Le.updatePlaceholdervalue = function(ele){
    mQuery('#formfield_properties_placeholder').val(ele);
}
