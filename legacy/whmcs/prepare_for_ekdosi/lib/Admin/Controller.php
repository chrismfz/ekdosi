<?php

namespace WHMCS\Module\Addon\PrepareForEkdosi\Admin;
use WHMCS\Database\Capsule;
/**
 * Sample Admin Area Controller
 */
class Controller {

    /**
     * Index action.
     *
     * @param array $vars Module configuration parameters
     *
     * @return string
     */
    public function index($vars)
    {
        // Get common module parameters
        $modulelink = $vars['modulelink']; // eg. addonmodules.php?module=addonmodule

        return <<<EOF

<form action="{$modulelink}&action=show" method="POST">	
  <div class="col-md-3">
    <input class="form-control" name="invoiceid" placeholder="Invoice ID, e.g. 12345" type="text">
  </div>
  <div class="col-md-3">
    <button class="btn btn-primary">Search</button>
  </div>
</form>
EOF;
    }

		public function reset($vars)
		{
			$modulelink = $vars['modulelink'];
			
			$invoice_id = $_POST['invoice_id'];
			$item_output = "";
			Capsule::table('tblinvoices')
				->where('id', $invoice_id)
				->update(
					[
						'invoiced' => 0,
					]
				);
				
            $currentUser = new \WHMCS\Authentication\CurrentUser;
            $user = $currentUser->user();
			logActivity("PrepareForEkdosi: Invoice #{$invoice_id} invoiced field set to 0.", $user->id);
			$output = <<<EOF

<p><div class="alert alert-success">Success!</div></p>
<p>The following invoice's <code>invoiced</code> field has been set to <code>0</code>.</p>
<p>
	<table class="table">
		<thead>
			<tr>
				<th>Invoice ID</th>
			</tr>
		</thead>
		<tbody>
		    <tr>
		        <td>
		            {$invoice_id}
		        </td>
		    </tr>    
		</tbody>
	</table>
</p>
<p>
	<form action="${modulelink}&action=show" method="POST">
		<input type="hidden" name="invoiceid" value="{$invoice_id}">
		<a href="{$modulelink}" class="btn btn-info">
			<i class="fa fa-arrow-left"></i>
			Back to search
		</a>
		<button class="btn btn-primary">Back to invoice #{$invoice_id}</button>
	</form>
</p>
EOF;
			return $output;
		}

    /**
     * Show action.
     *
     * @param array $vars Module configuration parameters
     *
     * @return string
     */
    public function show($vars)
    {
        // Get common module parameters
				$modulelink = $vars['modulelink']; // eg. addonmodules.php?module=addonmodule
				$invoiceid = $_POST['invoiceid'];
				$invoice = Capsule::table('tblinvoices')
					->find($invoiceid);
				if($invoice)
				{
					$invoice_items = Capsule::table('tblinvoiceitems')
						->where('invoiceid', $invoice->id)
						->get();
					$items_count = count($invoice_items);
					$tbody = "";
					foreach($invoice_items as $item)
					{
						$tbody .= <<<EOF

<tr>
	<td>{$item->description}</td>
</tr>
EOF;

					}
					$table = <<<EOF

<table class="table">
	<thead>
		<tr>
			<th>Description</th>
		</tr>
	</thead>
	<tbody>	
		{$tbody}
	</tbody>
</table>
EOF;
					return <<<EOF

<p>
	<a href="{$modulelink}" class="btn btn-info">
		<i class="fa fa-arrow-left"></i>
		Back to search
	</a>
</p>
<form method="POST" action="{$modulelink}&action=reset">
	<p><code>invoiced</code> value for <strong>Invoice #{$invoiceid}</strong> is <code>{$invoice->invoiced}</code>.</p>
	<p>{$table}</p>
	<p>
		<button class="btn btn-primary">Set invoiced to 0</button>
	</p>
	<input type="hidden" name="invoice_id" value="{$invoiceid}">
</form>
EOF;
				}
				else
				{
				return <<<EOF
<div class="alert alert-warning">Invoice id not found.</div>

<a href="{$modulelink}" class="btn btn-info">
	<i class="fa fa-arrow-left"></i>
	Back to search
</a>
EOF;

				}
    }
}
