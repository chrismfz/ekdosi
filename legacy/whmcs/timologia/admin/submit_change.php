<div id="result">
<?php
    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }
    use Illuminate\Database\Capsule\Manager as Capsule;
    /*   checkIfExists, ελέγχει αν υπάρχει εγγραφή σχετικά με την υπηρεσία   */
    function checkIfExists($serviceid,$servicetype) {
        return Capsule::table('mod_timologia')
        ->where('serviceid','=',$serviceid)
        ->where('service_type','=',$servicetype)
        ->count();
    } 
    /*  setContact, αντιστοιχεί υπηρεσία με επαφή   */
    function setContact($contactid,$serviceid,$userid,$servicetype) {
        $exists = checkIfExists($serviceid,$servicetype);
        if($exists==0) {
            Capsule::table('mod_timologia')
                ->insert(array('userid'=>$userid,'service_type'=>$servicetype,'serviceid'=>$serviceid,'contactid'=>$contactid));
            return 0; // 0 OK
        } elseif($exists==1) {
            Capsule::table('mod_timologia')
                ->where('serviceid','=',$serviceid)
                ->where('service_type','=',$servicetype)
                ->update(array('contactid'=>$contactid));
            return 0; //0 OK
        }
        else {
            return 1; //too many records? that's not good
        }
    }

    echo setContact($_POST['contactid'],$_POST['serviceid'],$_POST['userid'],$_POST['type']);
?>
</div>
