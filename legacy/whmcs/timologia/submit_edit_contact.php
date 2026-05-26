<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  use Illuminate\Database\Capsule\Manager as Capsule;
  /*  editContact function, παίρνει τις POST παραμέτρους και ενημερώνει την επαφή με Primary Key $id   */
  function editContact($id,$onoma,$poli,$address,$afm,$doy,$drastiriotita,$email,$comments,$address_2,$vies_vatno,$country,$userid,$postal_code,$telephone) {
    Capsule::table('mod_timologia_contacts')->where('id','=',$id)->where('userid','=',$userid)->update(array(
      'company_name'=>$onoma,
      'city'=>$poli,
      'address1'=>$address,
      'tax_office'=>$doy,
      'gr_vatno'=>$afm,
      'description'=>$drastiriotita,
      'email'=>$email,
      'address2'=>$address_2,
      'vies_vatno'=>$vies_vatno,
      'country'=>$country,
      'comments'=>$comments,
      'postal_code'=>$postal_code,
      'telephone'=>$telephone,
    ));
    return 0;
  }
  return editContact($_POST['contactid'],$_POST['company_name'],$_POST['city'],$_POST['address1'],$_POST['gr_vatno'],$_POST['tax_office'],$_POST['description'],$_POST['email'],$_POST['comments'],$_POST['address_2'],$_POST['vies_vatno'],$_POST['country'],$_SESSION['uid'],$_POST['postal_code'],$_POST['telephone']);
  //var_dump($_POST);
?>
