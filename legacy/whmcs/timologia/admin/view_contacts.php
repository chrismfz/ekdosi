<div class="container-fluid">
<?php 
	if (!defined("WHMCS")) {
		die("This file cannot be accessed directly");
	}
	use Illuminate\Database\Capsule\Manager as Capsule;

	if(isset($_GET['clientid']) && !empty($_GET['clientid'])) {
		$clientid = $_GET['clientid'];
		$clientinfo = Capsule::table('tblclients')
						->where('id','=',$clientid)
						->get(array('firstname','lastname'));
		if(empty($clientinfo)){
			echo '<div class="alert alert-danger">Δεν βρέθηκε ο πελάτης.</div>';
		} else {
			$clientinfo = get_object_vars($clientinfo[0]);
			$contacts = Capsule::table('mod_timologia_contacts')
						->where('userid','=',$clientid)
						->get();
?>
	<div class="col-md-12 well well-sm">
	    <a href="addonmodules.php?module=timologia&clid=<?php echo $clientid ?>" class="btn btn-default"><span class="glyphicon glyphicon-arrow-left"></span> Επιστροφή στη λίστα πελατών</a>
		<div class="page-header text-center"><h1><span class="glyphicon glyphicon-user"></span> <?php echo "{$clientinfo['firstname']} {$clientinfo['lastname']}"; ?></h1></div>
		<div class="col-md-12" id="message"></div>
			<div class="col-md-6 form-group">
				<input type="hidden" id="id" value="<?php echo $clientid; ?>">
				<input class="form-control" type="text" name="onoma" id="onoma" placeholder="Επωνυμία">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="poli" id="poli" placeholder="Πόλη">
			</div>
			<div class = "col-md-6 form-group">
				<input class="form-control" type="text" name="address" id="address" placeholder="Διεύθυνση">
			</div>
			<div class="col-md-6 form-group">
                <input type="text" name="address_2" id="address_2" placeholder="Διεύθυνση 2" class="form-control" value="">
            </div>
            <div class="col-md-6 form-group">
                <input class="form-control" type="text" name="postal_code" id="postal_code" placeholder="Τ.Κ." value="">
            </div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="doy" id="doy" placeholder="ΔΟΥ">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="afm" id="afm" placeholder="ΑΦΜ">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="vies_vatno" id="vies_vatno" placeholder="VIES VAT Number">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="drastiriotita" id="drastiriotita" placeholder="Δραστηριότητα">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="email" id="email" placeholder="E-mail">
			</div>
			<div class="col-md-6 form-group">
                <input class="form-control" type="text" name="telephone_" id="telephone_" placeholder="Τηλέφωνο" value="">
            </div>
			<div class="col-md-12 form-group">
				<textarea class="form-control" id="comments" name="comments" placeholder="Σχόλια (προαιρετικό)"></textarea>
			</div>
			<div class="col-md-12 form-group text-center">
				<button class="btn btn-primary" id="submit"><span class="glyphicon glyphicon-floppy-disk"></span> Προσθήκη επαφής</button>
			</div>
	</div>
	<div class="col-md-12 well well-sm">
		<div class="page-header text-center"><h2>Υπάρχουσες επαφές</h2></div>
<?php 
		if(empty($contacts)){
			echo '<div class="alert alert-info">Ο πελάτης δεν έχει επαφές</div>';
		} else {
?>
		<table class="table table-responsive">
			<thead>
				<tr>
					<th>Επωνυμία</th>
					<th>Ενέργειες</th>
				</tr>
			</thead>
			<tbody>	
<?php
			foreach ($contacts as $obj => $contact) {	
?>
				<tr>
					<td><?php echo "{$contact->company_name}"; ?></td>
					<td><a class="btn btn-primary btn-sm" href="addonmodules.php?module=timologia&act=edit-contact&id=<?php echo $contact->id;?>"><span class="glyphicon glyphicon-edit"></span> Επεξεργασία</a> <button class="btn btn-sm btn-default delete" data-contactid="<?php echo $contact->id; ?>"><span class="glyphicon glyphicon-trash"></span> Διαγραφή</button></td>
				</tr>
<?php
			}
?>
			</tbody>
		</table>
<?php
		}
?>
	</div>

<?php

		}

	} else {
		echo '<div class="alert alert-warning">Λάθος στο URL.</div>';
	}
?>
</div>
<script type="text/javascript">
	$(document).ready(function() {
		$('.delete').click(function() {
			var btn = $(this);
			var contactid = btn.data('contactid'); 	
			if ( confirm("Η διαγραφή της επαφής θα διαγράψει και τις αποθηκευμένες προτιμήσεις που σχετίζονται με αυτήν.") ){
				btn.parent().parent().fadeOut('slow');
				$.ajax({
					url: 'addonmodules.php?module=timologia&act=delete-contact',
					type: 'POST',
					data: {contactid: contactid}, 
				})
				.done(function(msg) {
					var code = $(msg).find('#result').text();
					if (code==0) {
						$("#message").html('<div class="alert alert-success">Η επαφή διαγράφηκε.</div>');
					} else {
						$("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά.</div>');
					}
				});
				
			}
		});

		$("#submit").click(function() {
			var onoma = $("#onoma").val();
			var poli = $("#poli").val();
			var address = $("#address").val();
			var afm = $("#afm").val();
			var doy = $("#doy").val();
			var drastiriotita = $("#drastiriotita").val();
			var vies_vatno = $("#vies_vatno").val();
			var email = $("#email").val();
			var comments = $("#comments").val();
			var postal_code = $("#postal_code").val();
			var telephone = $("#telephone_").val();
			var address_2 = $("#address_2").val();
			var id = $("#id").val();
			if ( onoma == "" || poli == "" || afm == "" || doy == "" || drastiriotita == "" || address == "" ){
				$("#message").html('<div class="alert alert-danger">Παρακαλώ συμπληρώστε όλα τα απαραίτητα πεδία.</div>');
			} else {
				$.ajax({
					url: 'addonmodules.php?module=timologia&act=add-contact',
					type: 'POST',
					data: { clientid:id, company_name:onoma, city:poli, address1:address, gr_vatno:afm, tax_office:doy, description:drastiriotita,comments:comments,vies_vatno:vies_vatno,email:email,postal_code:postal_code,telephone:telephone,address2:address_2},
				})
				.done(function(msg) {
					var code = $(msg).find('#result').text();
					if(code==0) {
						$("#onoma").val("");
						$("#poli").val("");
						$("#address").val("");
						$("#afm").val("");
						$("#drastiriotita").val("");
						$("#doy").val("");
						$("#comments").val("");
						$("#message").html('<div class="alert alert-success">Η επαφή προστέθηκε!</div>');
					} else {
						$("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά!</div>');
					}
				});
			}
		});		
	});
</script>