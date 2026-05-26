<div id="result"> 
<?php
    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }
    use Illuminate\Database\Capsule\Manager as Capsule;
    /* resetService, διαγράφει όποια εγγραφή συσχετισμένη με $serviceid και $servicetype */
    function resetService($serviceid,$servicetype) {
        Capsule::table('mod_timologia')
        ->where('serviceid','=',$serviceid)
        ->where('service_type','=',$servicetype)
        ->delete();
        return 0;
    }
    echo resetService($_POST['serviceid'],$_POST['type']);

?>
</div>
