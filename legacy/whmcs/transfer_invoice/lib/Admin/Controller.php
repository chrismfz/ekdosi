<?php

namespace WHMCS\Module\Addon\TransferInvoice\Admin;
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
		return $this->transfer($vars);
	}

	public function search_client()
	{
		// Output the JSON response
		header('Content-Type: application/json');

		$search = $_POST['query'];
		$results = Capsule::table('tblclients')
			->select('id', 'firstname', 'lastname')
			->where('firstname', 'like', '%' . $search . '%')
			->orWhere('lastname', 'like', '%' . $search . '%')
			->orWhere('id', $search)
			->get();
		echo json_encode($results);
		exit();
	}

	public function transfer($vars)
	{
		$modulelink = $vars['modulelink'];
		$inv_id = "";
		if(isset($_GET['inv_id']))
		{
			$inv_id = $_GET['inv_id'];
		}

		$return = <<<EOF
<!-- Modal -->
<div id="clientSearchModal" class="modal fade" role="dialog">
  <div class="modal-dialog">

    <!-- Modal content-->
    <div class="modal-content">
      <div class="modal-header">
	<button type="button" class="close" data-dismiss="modal">&times;</button>
	<h4 class="modal-title">Search client</h4>
      </div>
      <div class="modal-body">
	    <div class="col-md-8">
		<input class="form-control" id="modal_client_id" name="modal_client_id" placeholder="Client ID or name" type="text" value="">
	    </div>
	    <div class="col-md-1">
		<button type="button" class="btn btn-secondary" id="btnSearch"><i class="fas fa-search"></i> Search</button>
	    </div>
      <div class="col-md-12">
	<div id="results"><table class="table table-sm table-striped" ><thead><tr><th>ID</th><th>Name</th></tr></thead><tbody id="tbody_results"></tbody></table></div>
      </div>
  <div class="clearfix"></div>
      </div>
      <div class="modal-footer">
	<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>

  </div>
</div>

<form  class="form-horizontal" action="${modulelink}&action=transfer_submit" method="POST">
      <div class="form-group">
	<div class="col-md-3">
	  <div class="input-group">
	    <span class="input-group-addon">Invoice ID</span>
    <input class="form-control" id="inv_id" name="inv_id" placeholder="Invoice ID, e.g. 12345" type="text" value="{$inv_id}">
	  </div>
	</div>
      </div>
      <div class="form-group">
	<div class="col-md-3">
	  <div class="input-group">
	    <span class="input-group-addon">Client ID</span>
      <input class="form-control" id="client_id" name="client_id" placeholder="Client  ID, e.g. 12345" type="text">
	    <span class="input-group-btn">
    <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#clientSearchModal"><i class="fa fa-search"></i> Search client</button>
	    </span>
	  </div>
	</div>
      </div>
      <div class="form-group">
    <div class="col-md-2">
      <button type="button" class="btn btn-secondary" id="btnValidate"><i class="fas fa-check"></i> Validate</button>
    </div>
      </div>
  <div class="form-group">
    <div class="col-md-8">
      <div id="validation_results"></div>
    </div>
  </div>
</form>
<style>
    .modal_id_select {
      cursor: pointer;
    }
  </style>
<script>
  $(document).on('click','.modal_id_select', function()
  {
    var cl_val = $(this).data('cl_id');
    $("#client_id").val(cl_val);
    $("#clientSearchModal").modal('toggle');

  });
    $(document).on("click","#btnSearch", function ()
    {
	var query = $('#modal_client_id').val();

	$.ajax({
	    url: '{$modulelink}&action=search_client',
	    type: 'POST',
	    data: {query: query},
	    success: function(response) {
    $('#tbody_results').html('');
    response.forEach(function(cl){
      $("#tbody_results").append('<tr class="modal_id_select" data-cl_id='+cl.id+'><td>'+cl.id+'</td><td>'+cl.firstname+' '+cl.lastname+'</td></tr>');
    });
	    }
	});
    });
    $(document).on("click","#btnValidate", function ()
    {
  var inv_id =	$('#inv_id').val();
  var client_id = $('#client_id').val();
  if ( inv_id != "" && client_id != "" )
  {
    $.ajax({
	url: '{$modulelink}&action=validate',
	type: 'POST',
	data: {inv_id: inv_id, client_id: client_id},
	success: function(response) {
      $("#validation_results").html("");
      if ( response.new_client_valid && response.invoice_valid )
      {
	var output_table = '';
	response.inv_items.forEach( function ( inv_item )
	{
	  output_table += `<tr><td>\${inv_item.description}</td></tr>`;
	});
	var output = `
<div class="panel panel-info">
  <div class="panel-heading">Invoice #\${inv_id} transfer confirmation</div>
  <div class="panel-body">
  <div class='col-md-12'>From <span class="bg-info">\${response.old_client.firstname} \${response.old_client.lastname} (\${response.old_client.id})</span></div>
  <div class='col-md-12'>To <span class="bg-success">\${response.new_client.firstname} \${response.new_client.lastname} (\${response.new_client.id})</span></div>
  <div class="col-md-12">Invoice total: <span class="bg-info">\${response.invoice_total}</span></div>
  </div>
  <table class="table">
  <tbody>
    \${output_table}
  </tbody>
  </table>
  <div class="panel-footer">
  <button type="button" class="btn btn-primary" id="btnTransferInvoice" data-inv_id="\${inv_id}" data-client_id="\${response.new_client.id}"><i class="fas fa-exchange"></i> Transfer</button>
  </div>
</div>
`;
	$("#validation_results").append(output);
      }
      else
      {
	var output_reasons = '';
	if ( !response.new_client_valid )
	  output_reasons += 'Invalid client ID<br/>';
	if ( !response.invoice_valid )
	  output_reasons += 'Invalid invoice ID';

	var output = `
<div class="panel panel-warning">
  <div class="panel-heading">Problems found</div>
  <div class="panel-body">
  \${output_reasons}
  </div>
</div>
`;
	$("#validation_results").append(output);
      }
	}
    });

  }
    });
  $(document).on('click','#btnTransferInvoice', function () {
    var inv_id = $(this).data("inv_id");
    var client_id = $(this).data("client_id");

    $.ajax({
      url: '{$modulelink}&action=transfer_submit',
      type: 'POST',
      data: {inv_id: inv_id, client_id: client_id},
      success: function(response) {
	if ( response.result )
	{
	  $("#validation_results").html("OK!");
	}
	else
	{
	  $("#validation_results").html("Failed!");
	}
      }
    });
  });
</script>
EOF;
		return $return;
	}

	public function validate($vars)
	{
		// Output the JSON response
		header('Content-Type: application/json');

		$client_id = $_POST['client_id'];
		$inv_id = $_POST['inv_id'];
		$client = Capsule::table('tblclients')
			->select('id', 'firstname', 'lastname')
			->where('id', $client_id)
			->get();
		$ret = new \stdClass();
		if ( $client->count() == 1 )
		{
			$ret->new_client_valid = true;
			$ret->new_client = $client->first();
		}
		else
		{
			$ret->new_client_valid = false;
		}

		$invoice = Capsule::table('tblinvoices')
			->select('id','userid','total','status')
			->where('id',$inv_id)
			->get();
		if ( $invoice->count() == 1 )
		{
			$ret->invoice_valid = true;
			$ret->invoice_total = $invoice->first()->total;
		}
		else
		{
			$ret->invoice_valid = false;
		}

		if ( $ret->invoice_valid )
		{
			$old_client = Capsule::table('tblclients')
				->select('id', 'firstname', 'lastname')
				->where('id', $invoice[0]->userid)
				->first();
			$ret->old_client = $old_client;
			//get invoice data
			$ret->inv_items = Capsule::table('tblinvoiceitems')
				->select('description')
				->where('invoiceid', $inv_id)
				->get();

		}


		echo json_encode($ret);
		exit();
	}

	public function transfer_submit($vars)
	{
		// Output the JSON response
		header('Content-Type: application/json');

		$client_id = $_POST['client_id'];
		$inv_id = $_POST['inv_id'];
		$ret = new \stdClass();
		$client = Capsule::table('tblclients')
			->select('id', 'firstname', 'lastname')
			->where('id', $client_id)
			->get();

		$invoice = Capsule::table('tblinvoices')
			->select('id','userid','total','status')
			->where('id',$inv_id)
			->get();
		if ( $invoice->count() == 1 && $client->count() == 1 )
		{
			//transfer
			$invoice_old_user = Capsule::table('tblclients')
				->select('id','firstname','lastname')
				->where('id', $invoice->first()->userid)
				->get();
			$invoice_old_user = $invoice_old_user->first();
			$updateInvoice = Capsule::table('tblinvoices')
				->where('id', $invoice->first()->id)
				->update(
					[
						'userid' => $client->first()->id,
					]
				);

			$updateInvoiceItems = Capsule::table('tblinvoiceitems')
				->where('invoiceid', $invoice->first()->id)
				->update(
					[
						'userid' => $client->first()->id,
					]
				);
			$ret->result = true;
			logActivity("Transferring invoice #{$inv_id} from {$invoice_old_user->firstname} {$invoice_old_user->lastname} ({$invoice_old_user->id}) to {$client->first()->firstname} {$client->first()->lastname} ({$client->first()->id})",0);
		}
		else
		{
			//fail
			$ret->result = false;
		}

		echo json_encode($ret);
		die();
	}
}

