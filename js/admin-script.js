jQuery(document).ready(function($) {
    'use strict';
    
    var mediaUploader;
    
    // Upload logo button click
    $(document).on('click', '.pod-upload-btn', function(e) {
        e.preventDefault();
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        mediaUploader = wp.media({
            title: 'Select Payment Logo',
            button: {
                text: 'Use this image'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            
            $('#pod_logo_url').val(attachment.url);
            $('#pod-logo-preview').html('<img src="' + attachment.url + '" style="max-width:150px;max-height:100px;border:1px solid #ddd;padding:5px;">');
            $('.pod-remove-logo').show();
        });
        
        mediaUploader.open();
    });
    
    // Remove logo button
    $(document).on('click', '.pod-remove-logo', function(e) {
        e.preventDefault();
        $('#pod_logo_url').val('');
        $('#pod-logo-preview').html('');
        $(this).hide();
    });
    
    // Form submission
    $('#pod-payment-form').on('submit', function(e) {
        e.preventDefault();
        
        var paymentName = $('#pod_payment_name').val().trim();
        var logoUrl = $('#pod_logo_url').val().trim();
        
        // Validation: At least payment name OR logo must be provided
        if (!paymentName && !logoUrl) {
            alert('Please provide at least a Payment Name or Logo image before saving.');
            return false;
        }
        
        var submitBtn = $(this).find('button[type="submit"]');
        var originalText = submitBtn.text();
        
        submitBtn.prop('disabled', true).text('Saving...');
        
        var formData = {
            action: 'pod_save_payment_option',
            nonce: podAjax.nonce,
            payment_name: paymentName,
            logo_url: logoUrl,
            discount_label: $('#pod_discount').val(),
            adjustment_value: $('#pod_adjustment_value').val(),
            payment_plan_months: $('#pod_payment_plan').val(),
            option_id: $('#pod_option_id').val()
        };
        
        $.ajax({
            url: podAjax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    submitBtn.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Edit button click
    $(document).on('click', '.pod-edit-btn', function(e) {
        e.preventDefault();
        
        var optionId = $(this).data('id');
        var btn = $(this);
        
        btn.prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: podAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'pod_get_payment_option',
                nonce: podAjax.nonce,
                option_id: optionId
            },
            success: function(response) {
                if (response.success) {
                    var option = response.data;
                    
                    $('#pod_payment_name').val(option.payment_name);
                    $('#pod_logo_url').val(option.logo_url || '');
                    $('#pod_discount').val(option.discount_label || '');
                    $('#pod_adjustment_value').val(option.adjustment_value || 0);
                    $('#pod_payment_plan').val(option.payment_plan_months);
                    $('#pod_option_id').val(option.id);
                    
                    if (option.logo_url) {
                        $('#pod-logo-preview').html('<img src="' + option.logo_url + '" style="max-width:150px;max-height:100px;border:1px solid #ddd;padding:5px;">');
                        $('.pod-remove-logo').show();
                    } else {
                        $('#pod-logo-preview').html('');
                        $('.pod-remove-logo').hide();
                    }
                    
                    $('.pod-cancel-edit').show();
                    
                    $('html, body').animate({
                        scrollTop: $('#pod-payment-form').offset().top - 50
                    }, 500);
                } else {
                    alert('Error: ' + response.data);
                }
                
                btn.prop('disabled', false).text('Edit');
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                btn.prop('disabled', false).text('Edit');
            }
        });
    });
    
    // Cancel edit button
    $(document).on('click', '.pod-cancel-edit', function(e) {
        e.preventDefault();
        resetForm();
    });
    
    // Delete button click
    $(document).on('click', '.pod-delete-btn', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to delete this payment option?')) {
            return;
        }
        
        var optionId = $(this).data('id');
        var row = $(this).closest('tr');
        var btn = $(this);
        
        btn.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: podAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'pod_delete_payment_option',
                nonce: podAjax.nonce,
                option_id: optionId
            },
            success: function(response) {
                if (response.success) {
                    row.fadeOut(300, function() {
                        $(this).remove();
                        
                        if ($('#pod-options-list tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    alert('Error: ' + response.data);
                    btn.prop('disabled', false).text('Delete');
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                btn.prop('disabled', false).text('Delete');
            }
        });
    });
    
    // Toggle Active/Inactive Status
    $(document).on('change', '.pod-status-toggle', function() {
        var checkbox = $(this);
        var optionId = checkbox.data('id');
        var isActive = checkbox.is(':checked') ? 1 : 0;
        var row = checkbox.closest('tr');
        var originalState = !isActive; // Store original state before change
        
        // Disable checkbox during request
        checkbox.prop('disabled', true);
        
        $.ajax({
            url: podAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'pod_toggle_status',
                nonce: podAjax.nonce,
                option_id: optionId,
                is_active: isActive
            },
            success: function(response) {
                if (response.success) {
                    // Toggle the row styling
                    if (isActive) {
                        row.removeClass('pod-inactive-row');
                    } else {
                        row.addClass('pod-inactive-row');
                    }
                    // Re-enable checkbox
                    checkbox.prop('disabled', false);
                } else {
                    alert('Error: ' + response.data);
                    // Revert checkbox state to original
                    checkbox.prop('checked', originalState);
                    checkbox.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                // Revert checkbox state to original
                checkbox.prop('checked', originalState);
                checkbox.prop('disabled', false);
            }
        });
    });
    
    // Reset form function
    function resetForm() {
        $('#pod-payment-form')[0].reset();
        $('#pod_option_id').val('');
        $('#pod_logo_url').val('');
        $('#pod-logo-preview').html('');
        $('.pod-cancel-edit').hide();
        $('.pod-remove-logo').hide();
    }
});