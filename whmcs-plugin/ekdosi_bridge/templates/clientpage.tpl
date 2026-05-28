{* T-2: the client-area "Παραστατικά σε τρίτους (v2)" page. All markup is built
   in PHP (lib/Client/Controller.php) and injected here. nofilter because the
   controller already htmlspecialchars()es every user-supplied value. *}
<h2>Παραστατικά σε τρίτους <span class="label label-info">v2</span></h2>
{$pagecontent nofilter}
