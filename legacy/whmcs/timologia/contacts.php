<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;
  /*  Το script φένρει τις επαφές που σχετίζονται με τον συνδεδεμένο χρήστη   */
  $contacts_s = Capsule::select('SELECT company_name,gr_vatno,id FROM mod_timologia_contacts WHERE userid=?',array($_SESSION['uid']));
  $contacts = array();
  foreach ($contacts_s as $contact) {
    array_push($contacts, array("onoma"=>$contact->company_name,"afm"=>$contact->gr_vatno, "id"=>$contact->id));
  }
  $template = "contacts";
  $cvars = array(
    "userid"=>$_SESSION['uid'],
    "contacts"=>$contacts
  );
?>
