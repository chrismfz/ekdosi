<?php
use WHMCS\View\Menu\Item as MenuItem;


/*	Προσθήκη μενού στην περιοχή πελατών	*/
add_hook('ClientAreaPrimaryNavbar', 1, function (MenuItem $primaryNavbar)
{
  if (!is_null($primaryNavbar->getChild('Billing'))) {
        $primaryNavbar->getChild('Billing')
            ->addChild('Παραστατικά σε τρίτους', array(
                'label' => 'Παραστατικά σε τρίτους <sup><span class="label label-primary">Νέο<span></sup>',
                'uri' => 'index.php?m=timologia',
                'order' => '100',
            ));
    }
});
?>
