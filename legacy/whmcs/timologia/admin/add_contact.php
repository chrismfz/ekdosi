<div id="result">
<?php
	if (!defined("WHMCS")) {
		die("This file cannot be accessed directly");
	}
	use Illuminate\Database\Capsule\Manager as Capsule;
	/*	addContact, προσθέτει επαφή σύμφωνα με τις POST παραμέτρους	*/
	function addContact($userid, $onoma, $poli, $address, $afm, $doy, $drastiriotita, $comments,$vies_vatno,$email,$postal_code,$address2,$telephone) {
		Capsule::table('mod_timologia_contacts')
		->insert(
			[
				'company_name'=>$onoma,
				'city'=>$poli,
				'address1'=>$address,
				'gr_vatno'=>$afm,
				'vies_vatno'=>$vies_vatno,
				'email'=>$email,
				'tax_office'=>$doy,
				'description'=>$drastiriotita,
				'comments'=>$comments,
				'userid'=>$userid,
				'postal_code'=>$postal_code,
				'address2'=>$address2,
				'telephone'=>$telephone,
			]
		);
		return 0;
	}
	echo addContact($_POST['clientid'],$_POST['company_name'],$_POST['city'],$_POST['address1'],$_POST['gr_vatno'],$_POST['tax_office'],$_POST['description'],$_POST['comments'],$_POST['vies_vatno'],$_POST['email'],$_POST['postal_code'],$_POST['address2'],$_POST['telephone']);
?>
</div>