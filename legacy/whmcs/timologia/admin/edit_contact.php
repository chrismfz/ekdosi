<?php 
	if (!defined("WHMCS")) {
		die("This file cannot be accessed directly");
	}
	use Illuminate\Database\Capsule\Manager as Capsule;

	if (isset($_GET['id']) && !empty($_GET['id'])) {
		$id = $_GET['id'];
		$contact = Capsule::table('mod_timologia_contacts')
		->where('id','=',$id)
		->get();
		if(empty($contact)){
			echo '<div class="alert alert-warning">Δεν βρέθηκε η επαφή.</div>';
		} else {
			$contact = get_object_vars($contact[0]);
			$userid = $contact['userid'];
			$user = Capsule::table('tblclients')
			->where('id','=',$userid)
			->get(array('firstname','lastname'));
			$user = get_object_vars($user[0]);

?>
		<div class="col-md-12">
			<p>Η επαφή ανήκει στον πελάτη <span class="text-info"><?php echo "{$user['firstname']} {$user['lastname']}"; ?></span>. <a class="btn btn-default" href="clientssummary.php?userid=<?php echo $userid; ?>"><span class="glyphicon glyphicon-folder-open"></span> Καρτέλα πελάτη</a> <a class="btn btn-default" href="addonmodules.php?module=timologia&act=view-contacts&clientid=<?php echo $userid; ?>"><span class="glyphicon glyphicon-user"></span> Επαφές πελάτη</a></p>
			<input id="id" type="hidden" value="<?php echo $contact['id']; ?>">
			<div class="col-md-12" id="message"></div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="onoma" id="onoma" placeholder="Όνομα" value="<?php echo $contact['company_name']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="country" id="country" placeholder="Χώρα" value="<?php echo $contact['country']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="poli" id="poli" placeholder="Πόλη" value="<?php echo $contact['city']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="address" id="address" placeholder="Διεύθυνση" value="<?php echo $contact['address1']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="address2" id="address2" placeholder="Διεύθυνση 2" value="<?php echo $contact['address2']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="postal_code" id="postal_code" placeholder="Τ.Κ." value="<?php echo $contact['postal_code']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="doy" id="doy" placeholder="ΔΟΥ" value="<?php echo $contact['tax_office']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="afm" id="afm" placeholder="ΑΦΜ" value="<?php echo $contact['gr_vatno']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="vies_vatno" id="vies_vatno" placeholder="VIES VAT No" value="<?php echo $contact['vies_vatno']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="drastiriotita" id="drastiriotita" placeholder="Δραστηριότητα" value="<?php echo $contact['description']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="email" id="email" placeholder="E-mail" value="<?php echo $contact['email']; ?>">
			</div>
			<div class="col-md-6 form-group">
				<input class="form-control" type="text" name="telephone_" id="telephone" placeholder="Τηλέφωνο" value="<?php echo $contact['telephone']; ?>">
			</div>
			<div class="col-md-12 form-group">
				<textarea class="form-control" id="comments" name="comments" placeholder="Σχόλια (προαιρετικό)"><?php echo $contact['comments']; ?></textarea>
			</div>
			<div class="col-md-12 form-group text-center">
				<button class="btn btn-primary" id="submit"><span class="glyphicon glyphicon-floppy-disk"></span> Αποθήκευση επαφής</button>
			</div>
		</div>
		<script type="text/javascript">
			$(document).ready(function(){
				$('#submit').click(function(){
					var onoma = $('#onoma').val();
					var poli = $('#poli').val();
					var address = $("#address").val();
					var address2 = $("#address2").val();
					var postal_code = $("#postal_code").val();
					var doy = $('#doy').val();
					var afm = $('#afm').val();
					var id = $('#id').val();
					var drastiriotita = $('#drastiriotita').val();
					var email = $('#email').val();
					var comments = $('#comments').val();
					var telephone = $('#telephone').val();
					var vies_vatno = $('#vies_vatno').val();
					var country = $('#country').val();
					if ( onoma == '' || poli == '' || doy == '' || afm == '' || drastiriotita == '' || address == "" ){
						$('#message').html('<div class="alert alert-danger">Δεν έχουν συμπληρωθεί τα απαραίτητα πεδία.</div>');
					} else {
						$.ajax({
							url: 'addonmodules.php?module=timologia&act=edit-contact-submit',
							type: 'POST',
							data: {company_name:onoma,city:poli,address1:address,address2:address2,postal_code:postal_code,tax_office:doy,description:drastiriotita,email:email,gr_vatno:afm,comments:comments,contactid:id,telephone:telephone,vies_vatno:vies_vatno,country:country},
						})
						.done(function(msg) {
							var code = $(msg).find('#result').text();
							if(code == 0) {
								$('#message').html('<div class="alert alert-success">Η επφαφή ενημερώθηκε.</div>');
							}
						});
					}
				});
			});
		</script>
<?php
		}

	} else {
		echo '<div class="alert alert-warning">Δεν υπάρχει id στο URL.</div>';
	}
?>