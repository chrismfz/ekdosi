<div id="result"> 
<?php
    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }
    use Illuminate\Database\Capsule\Manager as Capsule;
    /* setInvType, αλλάζει τον τύπο του παραστατικού */
    function setInvType($timologio_id,$isReceipt,$userid) {
        try
        {
            Capsule::table('mod_timologia')
            ->where('id','=',$timologio_id)
            ->where('userid', $userid)
            ->update(
                [
                    'isReceipt' => $isReceipt,
                ]
            );
            return 0;
        }
        catch (\Exception $e)
        {
            return $e->getMessage();
        }
    }
    echo setInvType($_POST['timologio_id'],$_POST['isReceipt'],$_SESSION['uid']);

?>
</div>