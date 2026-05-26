<?php

  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;

  function deleteContact ($delete_id,$user_id) {
    //to-do delete relevant contact records
    Capsule::table('mod_timologia')->where('contactid','=',$delete_id)->where('userid','=',$user_id)->delete();
    return Capsule::table('mod_timologia_contacts')->where('id','=',$delete_id)->where('userid','=',$user_id)->delete();
  }
  echo deleteContact($_POST['delete_id'],$_SESSION['uid']);

?>
