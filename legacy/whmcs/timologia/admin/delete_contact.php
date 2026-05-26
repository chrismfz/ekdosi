<?php 
	if (!defined("WHMCS")) {
		die("This file cannot be accessed directly");
	}
	use Illuminate\Database\Capsule\Manager as Capsule;
	/*	deletePrefs, διαγράφει τις εγγραφές που είναι συσχετισμένες με το $contactid	*/
	function deletePrefs($contactid) {
		Capsule::table('mod_timologia')
		->where('contactid','=',$contactid)
		->delete();
		return 0;
	}

	/*	deleteContact, διαγράφει την επαφή με id = $contactid	*/
	function deleteContact($contactid) {
		deletePrefs($contactid);
		Capsule::table('mod_timologia_contacts')
		->where('id','=',$contactid)
		->delete();
		return 0;
	}

?>
<div id="result">
<?php
	echo deleteContact($_POST['contactid']);
?>
</div>