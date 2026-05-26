<?php
	if (!defined("WHMCS")) {
		die("This file cannot be accessed directly");
	}
	use Illuminate\Database\Capsule\Manager as Capsule;

	/*	Addon Configuration	*/
	function timologia_config() {
		$configarray = array(
			"name" => "Παραστατικά σε τρίτους",
			"description" => "Βοήθημα προς τους πελάτες για να κόβουν τα παραστατικά σε τρίτους.",
			"version" => "1.0",
			"author" => "MyIP Networks",
			"language" => "english",
		);
		return $configarray;
	}

	/*	function που τρέχει όταν ενεργοποιείται το addon	*/
	function timologia_activate() {
		$query = "CREATE TABLE `mod_timologia_contacts` ( `id` INT NOT NULL AUTO_INCREMENT , `onoma` TEXT , `poli` TEXT , `address` TEXT , `doy` TEXT , `afm` TEXT , `drastiriotita` TEXT , `comments` TEXT , `userid` INT(10) NOT NULL REFERENCES tblclients(id) ON UPDATE CASCADE , PRIMARY KEY (`id`)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
		$result = full_query($query);
		$query ="CREATE TABLE `mod_timologia` ( `id` INT NOT NULL AUTO_INCREMENT , `userid` INT NOT NULL , `contactid` INT NOT NULL , `serviceid` INT NOT NULL , `service_type` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL , PRIMARY KEY (`id`)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
		$result = full_query($query);
		return array('status'=>'success','description'=>'Module activated successfully!');
		return array('status'=>'error','description'=>'There was a problem activating the module.');
		return array('status'=>'info','description'=>'');
	}

	/*	function που τρέχει όταν απενεργοποιείται το addon	*/
	function timologia_deactivate() {
		//$query = "DROP TABLE `mod_timologia`";
		//$result = full_query($query);
		//$query = "DROP TABLE `mod_timologia_contacts`";
		//$result = full_query($query);
		return array('status'=>'success','description'=>'Module deactivated successfully!');
	    return array('status'=>'error','description'=>'There was a problem deactivating the module.');
	    return array('status'=>'info','description'=>'');
	}

	/*	function για την περιοχή του administration	*/
	function timologia_output($vars) {
		$modulelink = $vars['modulelink'];
		if(isset($_GET['act'])) {
			switch($_GET['act']){
				case "client-search":
					include("admin/client_search.php");
					break;
                case "set-default":
                    include("admin/set_default.php");
                    break;
                case "submit-change":
                    include("admin/submit_change.php");
                    break;
                case "edit-contact":
                	include("admin/edit_contact.php");
                	break;
                case "edit-contact-submit":
                	include("admin/submit_edit_contact.php");
                	break;
                case "view-contacts":
                	include("admin/view_contacts.php");
                	break;
                case "add-contact":
                	include("admin/add_contact.php");
                	break;
                case "delete-contact":
                	include("admin/delete_contact.php");
                	break;
                case "set-invType":
                    include("admin/set_inv_type.php");
                    break;
				default:
					include("admin/admin_area.php");
					break;
			}
		} else {
		include("admin/admin_area.php");
		}
	}


	/* function για την περιοχή πελατών	*/
	function timologia_clientarea($vars) {
		$modulelink = $vars['modulelink'];
        $version = $vars['version'];
		$LANG = $vars['_lang'];
		$invoices_array = array();
		$loggedin_user = null;
		if(isset($_SESSION['uid'])) {
				switch ($_GET['act']) {
					case "contacts":
						include("contacts.php");
						break;
					case "add-contact":
						include("add_contact.php");
						die();
						break;
					case "delete-contact":
						include("delete_contact.php");
						die();
						break;
					case "add-service":
						include("add_service.php");
						die();
						break;
					case "reset-service":
						include("reset_service.php");
						die();
						break;
					case "edit-contact":
						include("edit_contact.php");
						break;
					case "submit-edit-contact":
						include("submit_edit_contact.php");
						die();
						break;
					case "set-invType":
                        include("set_inv_type.php");
                        die();
                        break;
	    	    	default:
						include("logged.php");
						break;
				}

		} else {
			include("notlogged.php");
		}
		return array(
			"pagetitle" => "Παραστατικά σε τρίτους",
			"breadcrump" => array("index.php?m=timologia" => "Παραστατικά"),
			"templatefile" => $template,
			"require_login" => true,
			"force_ssl" => true,
			"vars" => $cvars
		);
	}
?>
