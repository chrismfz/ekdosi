<?php

namespace WHMCS\Module\Addon\RelidRemover\Admin;
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

			$item_ids = $_POST['item_id'];
			$invoice_id = $_POST['invoice_id'];
			$item_output = "";
			foreach($item_ids as $item_id)
			{
				Capsule::table('tblinvoiceitems')
					->where('id', $item_id)
					->where('invoiceid', $invoice_id)
					->update(
						[
							'relid' => 0,
						]
					);

				$item = Capsule::table('tblinvoiceitems')
					->where('id', $item_id)
					->where('invoiceid', $invoice_id)
					->first();

				$item_output .= <<<EOF

<tr>
	<td>
		{$item->description}
	</td>
</tr>
EOF;
			}
			$output = <<<EOF

<p><div class="alert alert-success">Success!</div></p>
<p>The following items' relid has been set to 0:</p>
<p>
	<table class="table">
		<thead>
			<tr>
				<th>Description</th>
			</tr>
		</thead>
		<tbody>
			{$item_output}
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
	<td><input class="item_id" type="checkbox" name="item_id[]" value="{$item->id}"></td>
	<td>{$item->relid}</td>
	<td>{$item->description}</td>
</tr>
EOF;

					}
					$table = <<<EOF

<table class="table">
	<thead>
		<tr>
			<th>Select</th>
			<th>relid</th>
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
	<p>Total invoice items for <strong>Invoice #{$invoiceid}</strong>: {$items_count}.</p>
	<p>{$table}</p>
	<p>
		<button type="button" class="btn btn-default select-all">Select all</button>
		<button class="btn btn-primary">Set relid to 0</button>
	</p>
	<input type="hidden" name="invoice_id" value="{$invoiceid}">
</form>
<script>
	$(document).ready(function ()
	{
		$(document).on('click', '.select-all', function()
		{
			$('.item_id').prop('checked', true);
		});
	});
</script>
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
