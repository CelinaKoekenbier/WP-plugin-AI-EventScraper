/**
 * Events to Posts Scraper – Admin interactions.
 *
 * Handles:
 * - “Run now” AJAX trigger (manual execution of Runner).
 * - “Test connection” ping to ensure nonce/capabilities are valid.
 * - Status log rendering, progress indicators, rich error messages.
 *
 * Loads on the settings page only (`Settings::render` enqueues this script).
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Run now button handler
    $('#apify-run-now').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $status = $('#apify-run-status');
        
        // Disable button and show loading state
        $button.prop('disabled', true)
               .html('<span class="apify-loading"></span>' + apifyEvents.strings.running);
        
        $status.removeClass('success error')
               .addClass('loading')
               .show()
               .html('<span class="apify-loading"></span>Starting event discovery...');
        
        // Make AJAX request
        console.log('Apify Events: Making AJAX request to:', apifyEvents.ajaxUrl);
        console.log('Apify Events: Nonce:', apifyEvents.nonce);
        
        $.ajax({
            url: apifyEvents.ajaxUrl,
            type: 'POST',
            data: {
                action: 'apify_events_run_now',
                nonce: apifyEvents.nonce
            },
            timeout: 300000, // 5 minutes timeout
            success: function(response) {
                console.log('Apify Events: AJAX success response:', response);
                if (response.success) {
                    $status.removeClass('loading')
                           .addClass('success')
                           .html('<span class="apify-status-indicator success"></span>' + 
                                 apifyEvents.strings.success);
                    
                    if (response.data && response.data.log) {
                        $status.append('<div class="apify-logs">' + 
                                      response.data.log.replace(/\n/g, '<br>') + 
                                      '</div>');
                    }
                    
                    // Update statistics if available
                    if (response.data && response.data.stats) {
                        updateStatistics(response.data.stats);
                    }

                    // Refresh the page so the sidebar "Last Run Log" updates without manual reload
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                    
                } else {
                    var errorMessage = 'Run completed with issues';
                    var errorDetails = '';
                    
                    if (response.data) {
                        if (typeof response.data === 'string') {
                            errorMessage = response.data;
                        } else if (response.data.message) {
                            errorMessage = response.data.message;
                        } else if (response.data.error) {
                            errorMessage = response.data.error;
                        } else if (response.log) {
                            // Show the log
                            errorMessage = 'Run completed - check log for details';
                        }
                        
                        // Add additional error details if available
                        if (response.data && response.data.file && response.data.line) {
                            errorDetails = '<br><small>File: ' + response.data.file + ' (line ' + response.data.line + ')</small>';
                        }
                        
                        // Add log if available
                        if (response.log) {
                            errorDetails += '<div class="apify-logs">' + response.log.replace(/\n/g, '<br>') + '</div>';
                        }
                    }
                    
                    $status.removeClass('loading')
                           .addClass('error')
                           .html('<span class="apify-status-indicator error"></span>' + errorMessage + errorDetails);
                }
            },
            error: function(xhr, status, error) {
                console.log('Apify Events: AJAX error:', xhr, status, error);
                console.log('Apify Events: Response text:', xhr.responseText);
                console.log('Apify Events: Response JSON:', xhr.responseJSON);
                
                var errorMessage = 'Unknown error occurred';
                var errorDetails = '';
                
                if (status === 'timeout') {
                    errorMessage = 'Request timed out. The process may still be running.';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    if (typeof xhr.responseJSON.data === 'string') {
                        errorMessage = xhr.responseJSON.data;
                    } else if (xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON.data.error) {
                        errorMessage = xhr.responseJSON.data.error;
                    }
                    
                    // Add additional error details if available
                    if (xhr.responseJSON.data.file && xhr.responseJSON.data.line) {
                        errorDetails = '<br><small>File: ' + xhr.responseJSON.data.file + ' (line ' + xhr.responseJSON.data.line + ')</small>';
                    }
                } else if (xhr.status) {
                    errorMessage = 'HTTP Error ' + xhr.status + ': ' + error;
                    if (xhr.responseText) {
                        errorDetails = '<br><small>Response: ' + xhr.responseText.substring(0, 200) + '</small>';
                    }
                } else {
                    errorMessage = 'Network error: ' + error;
                }
                
                $status.removeClass('loading')
                       .addClass('error')
                       .html('<span class="apify-status-indicator error"></span>' + errorMessage + errorDetails);
            },
            complete: function() {
                // Re-enable button
                $button.prop('disabled', false)
                       .text('Run Now');
            }
        });
    });
    
    // Test connection button
    $('#apify-test-connection').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $status = $('#apify-run-status');
        
        $button.prop('disabled', true).text('Testing...');
        $status.removeClass('success error loading').show().text('Testing connection...');
        
        console.log('Apify Events: Testing AJAX connection');
        
        $.ajax({
            url: apifyEvents.ajaxUrl,
            type: 'POST',
            data: {
                action: 'apify_events_test',
                nonce: apifyEvents.nonce
            },
            success: function(response) {
                console.log('Apify Events: Test response:', response);
                if (response.success) {
                    $status.addClass('success').text('Connection successful! ' + (response.data && response.data.message ? response.data.message : ''));
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : (response.data || 'Unknown error');
                    $status.addClass('error').text('Test failed: ' + msg);
                }
            },
            error: function(xhr, status, error) {
                console.log('Apify Events: Test error:', xhr, status, error);
                $status.addClass('error').text('Connection test failed: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Connection');
            }
        });
    });
    
    // Auto-refresh logs every 30 seconds when running
    var logRefreshInterval;
    
    function startLogRefresh() {
        logRefreshInterval = setInterval(function() {
            if ($('#apify-run-status').hasClass('loading')) {
                refreshLogs();
            } else {
                clearInterval(logRefreshInterval);
            }
        }, 30000);
    }
    
    function refreshLogs() {
        $.ajax({
            url: apifyEvents.ajaxUrl,
            type: 'POST',
            data: {
                action: 'apify_events_get_status',
                nonce: apifyEvents.nonce
            },
            success: function(response) {
                if (response.success && response.data.log) {
                    var $logContainer = $('#apify-run-status .apify-logs');
                    if ($logContainer.length) {
                        $logContainer.html(response.data.log.replace(/\n/g, '<br>'));
                    }
                }
            }
        });
    }
    
    // Form validation
    $('form').on('submit', function(e) {
        var $form = $(this);
        var hasErrors = false;
        
        // // Validate Apify token
        // var $tokenField = $form.find('input[name="apify_events_options[apify_token]"]');
        // if ($tokenField.length && !$tokenField.val().trim()) {
        //     showFieldError($tokenField, 'Apify token is required');
        //     hasErrors = true;
        // }
        
        // Validate queries
        var $queriesField = $form.find('textarea[name="apify_events_options[queries]"]');
        if ($queriesField.length && !$queriesField.val().trim()) {
            showFieldError($queriesField, 'At least one search query is required');
            hasErrors = true;
        }
        
        if (hasErrors) {
            e.preventDefault();
            return false;
        }
    });
    
    function showFieldError($field, message) {
        $field.addClass('error');
        
        var $errorDiv = $field.siblings('.field-error');
        if (!$errorDiv.length) {
            $errorDiv = $('<div class="field-error" style="color: #dc3232; font-size: 12px; margin-top: 5px;"></div>');
            $field.after($errorDiv);
        }
        
        $errorDiv.text(message);
        
        // Remove error on input
        $field.one('input change', function() {
            $field.removeClass('error');
            $errorDiv.remove();
        });
    }
    
    function updateStatistics(stats) {
        // Update any statistics displays on the page
        $('.apify-stats-discovered').text(stats.discovered || 0);
        $('.apify-stats-fetched').text(stats.fetched || 0);
        $('.apify-stats-parsed').text(stats.parsed || 0);
        $('.apify-stats-imported').text(stats.imported || 0);
        $('.apify-stats-skipped').text(stats.skipped || 0);
    }
    
    // Tooltip initialization
    $('.apify-tooltip').each(function() {
        var $this = $(this);
        var tooltip = $this.data('tooltip') || $this.attr('title');
        if (tooltip) {
            $this.attr('data-tooltip', tooltip);
            $this.removeAttr('title'); // Remove default browser tooltip
        }
    });
    
    // Confirm before running
    $('#apify-run-now').on('click', function(e) {
        if (!confirm('This will start the event discovery process. Continue?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Auto-save settings
    var settingsTimeout;
    $('input, textarea, select').on('change', function() {
        clearTimeout(settingsTimeout);
        settingsTimeout = setTimeout(function() {
            // Auto-save could be implemented here
        }, 2000);
    });
    
    // Initialize
    initializePage();
    
    function initializePage() {
        // Initialize page - no automatic status check needed
        console.log('AI Events Scraper: Page initialized');

        // Hide the floating save button when the original submit button is visible
        var $submitWrap = $('#apify-submit-wrap');
        var $footerActions = $('.apify-events-footer-actions');
        if ($submitWrap.length && $footerActions.length) {
            var toggleFloatingSave = function() {
                var rect = $submitWrap[0].getBoundingClientRect();
                var viewportH = window.innerHeight || document.documentElement.clientHeight;
                var inView = rect.top < viewportH && rect.bottom > 0;
                $footerActions.toggleClass('is-hidden', inView);
            };
            toggleFloatingSave();
            $(window).on('scroll resize', toggleFloatingSave);
        }
    }
});
