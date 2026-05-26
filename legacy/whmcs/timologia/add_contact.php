<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;
 
  /*	addContact function, παίρνει τις POST παραμέτρους μιας επαφής και τις βάζει στην ΒΔ		*/
  function addContact($onoma,$poli,$address,$doy,$afm,$drastiriotita,$email,$comments,$address_2,$vies_vatno,$country,$userid,$postal_code,$telephone){
    Capsule::table('mod_timologia_contacts')->insert(array(
        "company_name"=>$onoma,
        "city"=>$poli,
        "address1"=>$address,
        "tax_office"=>$doy,
        "gr_vatno"=>$afm,
        "description"=>$drastiriotita,
        "email"=>$email,
        "comments"=>$comments,
        "address2"=>$address_2,
        "vies_vatno"=>$vies_vatno,
        "country"=>$country,
        "userid"=>$userid));
    return 0;
  }

  if (!isset($_SESSION['uid'])){
    echo "1"; //not logged in error code
  } else {
    $loggedin_user = $_SESSION['uid'];
    $onoma = $_POST['onoma'];
    $poli = $_POST['poli'];
    $address = $_POST['address'];
    $doy = $_POST['doy'];
    $afm = $_POST['afm'];
    $drastiriotita = $_POST['drastiriotita'];
    $email = $_POST['email'];
    $comments = $_POST['comments'];
    $address_2 = $_POST['address_2'];
    $vies_vatno = $_POST['vies_vatno'];
    $country = $_POST['country'];
    $postal_code = $_POST['postal_code'];
    $telephone = $_POST['telephone'];
    echo addContact($onoma,$poli,$address,$doy,$afm,$drastiriotita,$email,$comments,$address_2,$vies_vatno,$country,$loggedin_user,$postal_code,$telephone);
  }
?>
