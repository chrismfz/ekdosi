<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;
  $userid = $_SESSION['uid'];
  $contactid = $_GET['id'];
  $code = null;
  $contactarray = null;
  $contact = Capsule::table('mod_timologia_contacts')->where('userid','=',$userid)->where('id','=',$contactid)->get();
  if(count($contact) == 1)  {
    $contactarray = $contact;
    $code = 0;
  } else {
    $code = 1;
  }
  $template = "edit_contact";
  $cvars = array(
    "contact"=>$contactarray,
    "code"=>$code,
  )
?>
