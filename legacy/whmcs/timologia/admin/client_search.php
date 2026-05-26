<div id="client_result">
<?php
  if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
  }
  /* Το script επιστρέφει σε JSON object τους πελάτες ή τις επαφές,υπηρεσίες,domains σύμφωνα με το $_GET['search'] */
  use Illuminate\Database\Capsule\Manager as Capsule;
  if($_GET['search']=='clients'){
    $query = trim($_POST['query']);
    $results_clients = Capsule::table('tblclients')
    ->where(Capsule::raw('concat(firstname," ",lastname," ",id)'),'LIKE','%'.$query.'%')
    ->orWhere(Capsule::raw('concat(lastname," ",firstname," ",id)'),'LIKE','%'.$query.'%')
    ->select(array('id','firstname','lastname'))
    ->get();
    $results_services = Capsule::table('mod_timologia')
        ->join('tblclients', 'mod_timologia.userid', '=', 'tblclients.id')
        ->join('tblhosting', 'mod_timologia.serviceid', '=', 'tblhosting.id')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->join('mod_timologia_contacts', 'mod_timologia.contactid', '=', 'mod_timologia_contacts.id')
        ->select('tblclients.firstname','tblclients.lastname','tblclients.id as clid','tblhosting.domain', 'tblproducts.name','mod_timologia_contacts.*', 'tblclients.email as parent_email')
        ->where('tblhosting.domain', 'LIKE', '%'.$query.'%')
        ->where('mod_timologia.service_type', 'hosting')
        ->get();
    $results_domains = Capsule::table('mod_timologia')
        ->join('tblclients', 'mod_timologia.userid', '=', 'tblclients.id')
        ->join('tbldomains', 'mod_timologia.serviceid', '=', 'tbldomains.id')
        ->join('mod_timologia_contacts', 'mod_timologia.contactid', '=', 'mod_timologia_contacts.id')
        ->select('tblclients.firstname','tblclients.lastname','tblclients.id as clid','tbldomains.domain','mod_timologia_contacts.*', 'tblclients.email as parent_email')
        ->where('tbldomains.domain', 'LIKE', '%'.$query.'%')
        ->where('mod_timologia.service_type', 'domain')
        ->get();
    $results = new \stdClass();
    $results->clients = $results_clients;
    $results->services = $results_services;
    $results->domains = $results_domains;
    echo htmlspecialchars(json_encode($results));
  } elseif ($_GET['search']=='contacts') {
    $services = Capsule::select('SELECT tblc.email as parent_email,h.id as serviceid,h.domain,h.userid,t.id as timologio_id,t.isReceipt,c.company_name,c.address1,c.address2,c.telephone,c.postal_code,c.vies_vatno,c.email,c.city,c.country,c.gr_vatno,c.tax_office,c.description,c.id as contactid,c.comments,prod.name as type  FROM (SELECT userid,domain,id,packageid FROM tblhosting where tblhosting.userid = ?) h
    	LEFT JOIN (SELECT * FROM mod_timologia WHERE mod_timologia.userid = ? AND mod_timologia.service_type = "hosting") t
    	ON h.id = t.serviceid
      LEFT JOIN (SELECT company_name,city,country,id,address1,gr_vatno,tax_office,description,postal_code,comments,address2,telephone,vies_vatno,email FROM mod_timologia_contacts WHERE mod_timologia_contacts.userid = ?) c
      ON t.contactid = c.id
      LEFT JOIN (SELECT email, id FROM tblclients) tblc
      ON h.userid = tblc.id
      LEFT JOIN (SELECT * FROM tblproducts) prod
      ON h.packageid = prod.id ORDER BY h.domain ASC',array($_POST['clientid'],$_POST['clientid'],$_POST['clientid']));
    $domains = Capsule::select('SELECT tblc.email as parent_email,d.id as serviceid,d.domain,d.userid,t.id as timologio_id,t.isReceipt,c.company_name,c.city,c.country,c.address1,c.telephone,c.address2,c.postal_code,c.vies_vatno,c.email,c.gr_vatno,c.tax_office,c.description,c.id as contactid,c.comments  FROM (SELECT userid,domain,id FROM tbldomains where tbldomains.userid = ?) d
      LEFT JOIN (SELECT * FROM mod_timologia WHERE mod_timologia.userid = ? AND mod_timologia.service_type = "domain") t
      ON d.id = t.serviceid
      LEFT JOIN (SELECT email, id FROM tblclients) tblc
      ON d.userid = tblc.id
      LEFT JOIN (SELECT company_name,city,country,id,address1,gr_vatno,tax_office,description,comments,postal_code,address2,vies_vatno,telephone,email FROM mod_timologia_contacts WHERE mod_timologia_contacts.userid = ?) c
      ON t.contactid = c.id ORDER BY d.domain ASC',array($_POST['clientid'],$_POST['clientid'],$_POST['clientid']));
    $contacts = Capsule::table('mod_timologia_contacts')
        ->where('userid','=',$_POST['clientid'])
        ->select(array('id','company_name','city'))
        ->get();
      echo('<div id="services">'.json_encode($services).'</div>');
      echo('<div id="domains">'.json_encode($domains).'</div>');
      echo('<div id="contacts">'.htmlspecialchars(json_encode($contacts)).'</div>');
  }
?>
</div>
