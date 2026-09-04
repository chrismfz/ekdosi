{* Client-area "Εκδοθέντα Παραστατικά" page. All markup is built in PHP
   (lib/Client/IssuedController.php) and injected here. nofilter because the
   controller already htmlspecialchars()es every value it renders. *}
<h2>Εκδοθέντα Παραστατικά</h2>
{$pagecontent nofilter}
