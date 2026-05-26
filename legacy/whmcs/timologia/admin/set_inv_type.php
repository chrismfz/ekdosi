<div id="result"> 
<?php
    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }
    use Illuminate\Database\Capsule\Manager as Capsule;
    /* setInvType, αλλάζει τον τύπο του παραστατικού */
    function setInvType($timologio_id,$isReceipt) {
        try
        {
            Capsule::table('mod_timologia')
            ->where('id','=',$timologio_id)
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
    echo setInvType($_POST['timologio_id'],$_POST['isReceipt']);

?>
</div>
