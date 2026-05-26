<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;

  /*  Έλεγχος αν έχει ήδη καταχωρηθεί η συγκεκριμένη υπηρεσία   */
  function checkIfExists($serviceid,$servicetype){
    //returns 0 if no record found in db, else returns the no. of rows
    return Capsule::table('mod_timologia')->where('service_type', '=', $servicetype)->where('serviceid', '=', $serviceid)->count();
  }

  /*  Αντιστοίχιση υπηρεσίας με επαφή  */
  function addService($serviceid, $servicetype, $contactid, $userid) {
    $exists = checkIfExists($serviceid,$servicetype);
    if ( $exists==0 ) { //no record in db
      Capsule::table('mod_timologia')->insert(array('userid'=>$userid,'service_type'=>$servicetype,'serviceid'=>$serviceid,'contactid'=>$contactid));
      return 0;
    } elseif ( $exists==1 ) { //found in db, update
      Capsule::table('mod_timologia')->where('serviceid','=',$serviceid)->where('service_type','=',$servicetype)->update(array('contactid'=>$contactid));
      return 0;
    } else {
      return "Too many records or something wrong";
    }
  }
  
  echo addService($_POST['serviceid'],$_POST['servicetype'],$_POST['contactid'],$_SESSION['uid']);
?>
