<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;
  /*	Διαγράφει την όποια προτίμηση σχετικά με την υπηρεσία	*/
  function resetService($serviceid,$servicetype,$userid) {
    Capsule::table('mod_timologia')->where('serviceid','=',$serviceid)->where('service_type','=',$servicetype)->where('userid','=',$userid)->delete();
    return 0;
  }
  echo resetService($_POST['serviceid'],$_POST['servicetype'],$_SESSION['uid']);
?>
