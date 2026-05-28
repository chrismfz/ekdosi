<?php
/**
 * WHMCS SDK Sample Addon Module Hooks File
 *
 * Hooks allow you to tie into events that occur within the WHMCS application.
 *
 * This allows you to execute your own code in addition to, or sometimes even
 * instead of that which WHMCS executes by default.
 *
 * @see https://developers.whmcs.com/hooks/
 *
 * @copyright Copyright (c) WHMCS Limited 2017
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Require any libraries needed for the module to function.
// require_once __DIR__ . '/path/to/library/loader.php';
//
// Also, perform any initialization required by the service's library.

/**
 * Register a hook with WHMCS.
 *
 * This sample demonstrates triggering a service call when a change is made to
 * a client profile within WHMCS.
 *
 * For more information, please refer to https://developers.whmcs.com/hooks/
 *
 * add_hook(string $hookPointName, int $priority, string|array|Closure $function)
 */
add_hook('AdminInvoicesControlsOutput', 1, function($vars) {
    global $CONFIG;
    $URL = $CONFIG['SystemURL'];
    //return json_encode($vars);
    return <<<EOF
<script>$('#viewInvoiceAsClientButton').parent().prepend('<a href="{$URL}/sysadmin/addonmodules.php?module=transfer_invoice&action=transfer&inv_id={$vars['invoiceid']}" id="transferInvoiceButton" type="button" class="btn btn-default" onclick=""> \
            <i class="fas fa-exchange"></i> Transfer invoice</a>');</script>
EOF;

});
