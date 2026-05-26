<div id="result">
<?php
	if (!defined("WHMCS")) {
		die("This file cannot be accessed directly");
	}
	use Illuminate\Database\Capsule\Manager as Capsule;

	/*	editContact, ενημέρωση πεδίων επαφής σύμφωνα με τις POST παραμέτρους	*/
	function editContact($contactid,$onoma,$poli,$address,$afm,$doy,$comments,$drastiriotita,$email,$address2,$country,$postal_code,$telephone,$vies_vatno) {
		Capsule::table('mod_timologia_contacts')
		->where('id','=',$contactid)
		->update(array(
			'company_name'=>$onoma,
			'city'=>$poli,
			'address1'=>$address,
			'address2'=>$address2,
			'country'=>$country,
			'gr_vatno'=>$afm,
			'tax_office'=>$doy,
			'description'=>$drastiriotita,
			'comments'=>$comments,
			'email'=>$email,
			'postal_code'=>$postal_code,
			'telephone'=>$telephone,
			'vies_vatno'=>$vies_vatno,
			)
		);
		return 0;
	}
	echo editContact($_POST['contactid'],$_POST['company_name'],$_POST['city'],$_POST['address1'],$_POST['gr_vatno'],$_POST['tax_office'],$_POST['comments'],$_POST['description'],$_POST['email'],$_POST['address2'],$_POST['country'],$_POST['postal_code'],$_POST['telephone'],$_POST['vies_vatno']);
?>
</div>