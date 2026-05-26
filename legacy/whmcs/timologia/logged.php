<?php
if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}
use Illuminate\Database\Capsule\Manager as Capsule;
/*  Το script φέρνει τις υπηρεσίες,επαφές,domains που σχετίζονται με τον χρήστη που είναι συνδεδεμένος  */

$loggedin_user = $_SESSION['uid'];

$contacts_s = Capsule::select('SELECT id,company_name,city,gr_vatno FROM mod_timologia_contacts WHERE userid=?',array($loggedin_user));
$contacts = array();
foreach ($contacts_s as $c)
{
  array_push($contacts,array("id"=>$c->id,"onoma"=>$c->company_name,"poli"=>$c->city,"afm"=>$c->gr_vatno));
}

$hosting_services = Capsule::select('SELECT h.id as serviceid,h.domain,t.id as timologio_id,t.isReceipt,c.company_name,prod.name as type  FROM (SELECT domain,id,packageid FROM tblhosting where tblhosting.userid = ?) h
	LEFT JOIN (SELECT * FROM mod_timologia WHERE mod_timologia.userid = ? AND mod_timologia.service_type = "hosting") t
	ON h.id = t.serviceid
  LEFT JOIN (SELECT company_name,id FROM mod_timologia_contacts WHERE mod_timologia_contacts.userid = ?) c
  ON t.contactid = c.id
  LEFT JOIN (SELECT * FROM tblproducts) prod
  ON h.packageid = prod.id',array($loggedin_user,$loggedin_user,$loggedin_user));
$hosting = array();

foreach ($hosting_services as $h) {
  array_push($hosting,array("domain"=>$h->domain, "onoma"=>$h->company_name,"serviceid"=>$h->serviceid,"timologio_id"=>$h->timologio_id,"type"=>$h->type,"is_receipt"=>$h->isReceipt));

}

$domain_services = Capsule::select('SELECT d.id as serviceid,d.domain,t.id as timologio_id,t.isReceipt,c.company_name  FROM (SELECT domain,id FROM tbldomains where tbldomains.userid = ?) d
	LEFT JOIN (SELECT * FROM mod_timologia WHERE mod_timologia.userid = ? AND mod_timologia.service_type = "domain") t
	ON d.id = t.serviceid
    LEFT JOIN (SELECT company_name,id FROM mod_timologia_contacts WHERE mod_timologia_contacts.userid = ?) c
    ON t.contactid = c.id',array($loggedin_user, $loggedin_user, $loggedin_user));
$domains = array();
foreach ($domain_services as $d) {
  array_push($domains, array("timologio_id"=>$d->timologio_id, "domain"=>$d->domain,"onoma"=>$d->company_name,"serviceid"=>$d->serviceid,"is_receipt"=>$d->isReceipt));
}

$template = "timologia";
$cvars = array(
  "contacts" => $contacts,
  "domains" => $domains,
  "hosting" => $hosting,
  "userid" => $loggedin_user,
)
?>
