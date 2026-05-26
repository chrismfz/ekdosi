<?php
    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }
    use Illuminate\Database\Capsule\Manager as Capsule;
    $ids = Capsule::table('mod_timologia')->distinct()->select('userid')->get();
    $ids_array = array();
    foreach($ids as $id) {
        array_push($ids_array,$id->userid);
    }
    $result = Capsule::table('tblclients')
    ->select('firstname','lastname','id')
    ->whereIn('id',$ids_array)
    ->orderBy('firstname', 'ASC')
    ->get();
?>
<div class="col-md-12">
  <div class="panel panel-default">
    <div class="panel-body">
      <div class="col-md-12">
        <div class="panel panel-primary">
            <div class="panel-heading text-center">
                <p class="h3"><span class="glyphicon glyphicon-search"></span> Αναζήτηση</p>
            </div>
            <div class="panel-body">
                <div class="col-md-4">
                    <div class="well well-sm">
                    <div class="input-group">
                        <span class="input-group-addon" id="basic-addon1"><span class="glyphicon glyphicon-search"></span> </span>
                        <input type="text" name="txtSearch" id="txtSearch" class="form-control" placeholder="ID/όνομα/επίθετο/domain" value="<?php echo (isset($_GET['clid']) ? $_GET['clid'] : '' ) ?>">
                        <div class="input-group-btn">
                            <button class="btn btn-primary" id="btnSearch">Αναζήτηση</button>
                        </div>
                    </div>
                    <div style="margin-top:10px; margin-bottom:10px;" class="text-center"><button class="btn btn-info" id="toggle_predefined"><span class="glyphicon glyphicon-eye-close"></span> Απόκρυψη/Εμφάνιση πίνακα</button></div>
                        <div id="predefined_results">
                            <div class="well well-sm">
                                <table class="table table-condensed table-responsive">
                                    <thead>
                                        <th colspan="2">
                                            <p class="h3 text-center">Πελάτες με καταχωρημένες προτιμήσεις</p>
                                        </th>
                                    </thead>
                                    <tbody>
                                        <?php
                                            foreach($result as $client){
                                        ?>
                                        <tr><th><?php echo "{$client->firstname} {$client->lastname}"; ?></th><td><div class="btn-group" role="group"><button class="btn btn-default btn-sm srch" data-clid="<?php echo "{$client->id}"; ?>"><span class="glyphicon glyphicon-th-list"></span> Υπηρεσίες</button> <a class="btn btn-default btn-sm" href="addonmodules.php?module=timologia&act=view-contacts&clientid=<?php echo "{$client->id}"; ?>"><span class="glyphicon glyphicon-user"></span> Επαφές</a></div></td></tr>    
                                        <?php
                                            }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div id="results"></div>
                    </div>
                </div>
                <div class="col-md-8">
                     <div id="cl_services">
        </div>
        <div id="modals">
            <div id="timologio_type_modal" class="modal fade">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title" id="timologio_type_modal_title"></h4>
                        </div>
                        <div class="modal-body">
                            <div id="timologio_type_modal_message"></div>
                            <input type="hidden" id="timologio_type_modal_id">
                            <div class="radio">
                                <label><input id="timologio_isReceipt_0" type="radio" name="timologio_isReceipt" value=0>Τιμολόγιο</label>
                            </div>
                            <div class="radio">
                                <label><input id="timologio_isReceipt_1" type="radio" name="timologio_isReceipt" value=1>Απόδειξη</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button id="timologio_type_modal_btnSave" type="button" class="btn btn-primary">Αποθήκευση</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="contact_modal" class="modal fade">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title" id="contact_company_name"></h4>
                  </div>
                  <div class="modal-body">
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Ονομασία</span>
                               	<input class="form-control" type="text" id="modal_company_name" placeholder="Επωνυμία" readonly>
                               	<span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_company_name"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">ΑΦΜ</span>
                                <input class="form-control" type="text" id="modal_gr_viesvat" placeholder="ΑΦΜ" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_gr_viesvat"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">ΔΟΥ</span>    
                                <input class="form-control" type="text" id="modal_tax_office" placeholder="ΔΟΥ" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_tax_office"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Δραστηριότητα</span>
                                <input class="form-control" type="text" id="modal_description" placeholder="Δραστηριότητα" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_description"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">E-mail</span>
                            	<input class="form-control" type="text" id="modal_email" placeholder="E-mail" readonly>
                            	<span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_email"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Parent e-mail</span>
                            	<input class="form-control" type="text" id="modal_parent_email" placeholder="Parent e-mail" readonly>
                            	<span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_parent_email"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Τηλέφωνο</span>
                            	<input class="form-control" type="text" id="modal_telephone" placeholder="Τηλέφωνο" readonly>
                            	<span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_telephone"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Χώρα</span>
                                <input class="form-control" type="text" id="modal_country" placeholder="Χώρα" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_country"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Πόλη</span>
                                <input class="form-control" type="text" id="modal_poli" placeholder="Πόλη" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_poli"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class = "col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Δ/νση</span>
                                <input class="form-control" type="text" id="modal_address" placeholder="Διεύθυνση" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_address"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class = "col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Δ/νση 2</span>
                                <input class="form-control" type="text" id="modal_address2" placeholder="Διεύθυνση 2" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_address2"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class = "col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">Τ.Κ.</span>
                                <input class="form-control" type="text" id="modal_postal_code" placeholder="Ταχ. Κώδικας" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_postal_code"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <div class="input-group">
                                <span class="input-group-addon">VIES VAT Number</span>
                                <input class="form-control" type="text"  id="modal_vies_vatno" placeholder="VIES VAT Number" readonly>
                                <span class="input-group-btn">
                                   	<button class="btn btn-default copy_to_clip" data-copy_element="modal_vies_vatno"><span class="glyphicon glyphicon-copy"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                        	<textarea class="form-control" id="modal_comments" placeholder="Σχόλια (προαιρετικό)" readonly></textarea>
                        </div>
                        <div class="clear"></div>
                  </div>
                  <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Κλείσιμο</button>
                  </div>
                </div>
              </div>
             </div>
        </div>
                </div>
            </div>
        </div>
      </div>
      <div class="col-md-12">
       
      </div>
    </div>
  </div>
</div>
<script type="text/javascript">
  $(document).ready(function(){
    $("#results, #predefined_results").on('click', 'button.srch', function() {//fetch client data
        var clientid = $(this).data("clid");
        var client = $(this).text();
        $.ajax({
          url: "addonmodules.php?module=timologia&act=client-search&search=contacts",
          type: "post",
          dataType: "html",
          data: {clientid:clientid},
        }).done(function(msg){
          var arr = JSON.parse($(msg).find("#services").text());
          var arr_domains = JSON.parse($(msg).find("#domains").text());
          var arr_contacts = JSON.parse($(msg).find("#contacts").text());
          var services = '<div class="well well-sm"><h2 class="text-center">Υπηρεσίες</h2><br><div id="message"></div><table class="table table-condensed table-striped"><thead><tr><th>Υπηρεσία</th><th>Επαφή</th><th>Τύπος</th></tr></thead><tbody>';
          var contacts = "";
          for (var x = 0; x < arr_contacts.length; x++) {
            var cont = arr_contacts[x];
             contacts+= '<option value="'+cont['id']+'">'+cont['company_name']+'</option>';
          }
            contacts += '</select>';
          for(var i = 0;i < arr.length; i++) {
            var obj = arr[i];
            if(obj['company_name']!=null) {
                services += '<tr><td>'+obj['domain']+' <div class="label label-info">'+obj['type']+'</div></td><td><span class="contact">'+obj['company_name']+' <button type="button" class="btn btn-info btn-xs contact_btn" data-comments="' + obj['comments'] + '" data-country="'+obj['country']+'" data-company_name="'+obj['company_name']+'" data-postal_code="'+obj['postal_code']+'" data-city="'+obj['city']+'" data-parent_email="'+obj['parent_email']+'" data-address1="'+obj['address1']+'" data-address2="'+obj['address2']+'" data-telephone="'+obj['telephone']+'" data-gr_vatno="'+obj['gr_vatno']+'" data-vies_vatno="'+obj['vies_vatno']+'" data-city="'+obj['city']+'" data-email="'+obj['email']+'" data-description="'+obj['description']+'" data-tax_office="'+obj['tax_office']+'"><span class="glyphicon glyphicon-eye-open"></span></button></span> <button class="btn btn-default btn-xs edit_contact">αλλαγή</button> <span class="hidden"><select id="cont">'+contacts+' <button data-type="hosting" data-serviceid="'+obj['serviceid']+'" data-userid="'+obj['userid']+'" class="btn btn-primary btn-xs save"><span class="glyphicon glyphicon-ok"></span></button> <button data-serviceid="'+obj['serviceid']+'" data-type="hosting" class="btn btn-xs btn-default reset"><span class="glyphicon glyphicon-repeat"></span> προκαθορισμένο</button></span></td><td><button class="btn btn-default btn-xs btnEditType" data-timologio_title="'+obj['domain']+'" data-timologio_isReceipt="'+obj['isReceipt']+'" data-timologio_id="'+obj['timologio_id']+'">'+invType(obj['isReceipt'])+'</button></td></tr>';
            } else {
                services += '<tr><td>'+obj['domain']+' <div class="label label-info">'+obj['type']+'</div></td><td><span class="contact">προκαθορισμένο</span> <button class="btn btn-default btn-xs edit_contact">αλλαγή</button> <span class="hidden"><select id="cont">'+contacts+' <button data-serviceid="'+obj['serviceid']+'" data-type="hosting" data-userid="'+obj['userid']+'" class="btn btn-primary btn-xs save"><span class="glyphicon glyphicon-ok"></span></button> <button data-serviceid="'+obj['serviceid']+'" data-type="hosting" class="btn btn-xs btn-default reset"><span class="glyphicon glyphicon-repeat"></span> προκαθορισμένο</button></span></td><td>προκαθορισμένο</td></tr>';
            }
          }
          for (var i = 0; i < arr_domains.length; i++) {
              var obj = arr_domains[i];
              if(obj['company_name']==null) {
                services += '<tr><td>'+obj['domain']+' <div class="label label-primary">domain</div></td><td><span class="contact">προκαθορισμένο</span> <button class="btn btn-default btn-xs edit_contact">αλλαγή</button> <span class="hidden"><select id="cont">'+contacts+' <button data-serviceid="'+obj['serviceid']+'" data-type="domain" data-userid="'+obj['userid']+'" class="btn btn-primary btn-xs save"><span class="glyphicon glyphicon-ok"></span></button> <button data-serviceid="'+obj['serviceid']+'" data-type="domain" class="btn btn-xs btn-default reset"><span class="glyphicon glyphicon-repeat"></span> προκαθορισμένο</button></span></td><td>προκαθορισμένο</td></tr>';
              }
              else { 
                services += '<tr><td>'+obj['domain']+'<div class="label label-primary">Domain</div></td><td><span class="contact">'+obj['company_name']+' <button type="button" class="btn btn-info btn-xs contact_btn" data-comments="' + obj['comments'] + '" data-country="'+obj['country']+'" data-company_name="'+obj['company_name']+'" data-city="'+obj['city']+'" data-parent_email="'+obj['parent_email']+'" data-address1="'+obj['address1']+'" data-address2="'+obj['address2']+'" data-telephone="'+obj['telephone']+'" data-postal_code="'+obj['postal_code']+'" data-gr_vatno="'+obj['gr_vatno']+'" data-vies_vatno="'+obj['vies_vatno']+'" data-city="'+obj['city']+'" data-email="'+obj['email']+'" data-description="'+obj['description']+'" data-tax_office="'+obj['tax_office']+'"><span class="glyphicon glyphicon-eye-open"></span></button></span> <button class="btn btn-default btn-xs edit_contact">αλλαγή</button> <span class="hidden"><select id="cont">'+contacts+' <button data-serviceid="'+obj['serviceid']+'" data-type="domain" data-userid="'+obj['userid']+'" class="btn btn-primary btn-xs save"><span class="glyphicon glyphicon-ok"></span></button> <button data-serviceid="'+obj['serviceid']+'" data-type="domain" class="btn btn-xs btn-default reset"><span class="glyphicon glyphicon-repeat"></span> προκαθορισμένο</button></span></td><td><button class="btn btn-default btn-xs btnEditType" data-timologio_title="'+obj['domain']+'" data-timologio_isReceipt="'+obj['isReceipt']+'" data-timologio_id="'+obj['timologio_id']+'">'+invType(obj['isReceipt'])+'</button></td></tr>';
              }
          }
          services += '</tbody></table></div>';
          $("#cl_services").slideUp("fast", function() {
              $("#cl_services").html(services);
              $("#cl_services").slideDown("slow");
          });
        });
    }); //end fetch client data

    function invType(type)
    {
        if(type == null || type == 0)
        {
            return "Τιμολόγιο";
        }
        else
        {
            return "Απόδειξη";
        }
    }
    
    $(document).on('click', 'button.btnEditType', function()
    {
        $('#timologio_type_modal_message').html('');
        $('#timologio_type_modal_title').html($(this).data('timologio_title'));
        $('#timologio_type_modal_id').val($(this).data("timologio_id"));
        if ($(this).data('timologio_isreceipt') == 0 || $(this).data('timologio_isreceipt') == null || $(this).data('timologio_isreceipt') == "0")
        {
            $('#timologio_isReceipt_0').prop('checked', true);
        }
        else
        {
            $('#timologio_isReceipt_1').prop('checked', true);
        }
        $('#timologio_type_modal').modal('show'); 
    });
    
    $(document).on('click', '#timologio_type_modal_btnSave', function()
    {
        var id = $('#timologio_type_modal_id').val();
        var isReceipt = 0;
        if ($('#timologio_isReceipt_1').prop('checked') == true)
        {
            isReceipt = 1;
        }
        $.ajax({
            url: "addonmodules.php?module=timologia&act=set-invType",
            type: "post",
            data: { timologio_id:id,isReceipt:isReceipt },
        }).done(function(msg){
            var code = $(msg).find('#result').text();
            if(code == 0){
                $('#timologio_type_modal_message').html('<div class="alert alert-success">Η αλλαγή αποθηκεύτηκε.</div>');
                $('button.btnEditType[data-timologio_id="'+id+'"]').html(invType(isReceipt));
            } else {
                $('#timologio_type_modal_message').html('<div class="alert alert-danger">'+code+'</div>');
            }
        });
    });
    
    $(document).on('click', 'button.edit_contact', function()
    {
        hidden_span = $(this).next();
        $(hidden_span).toggleClass("hidden");
    });

    $(document).on('click','button.reset', function(){
        var btn_clicked = $(this);
        var serviceid = $(this).data('serviceid');
        var type = $(this).data('type');
        $.ajax({
            url: "addonmodules.php?module=timologia&act=set-default",
            type: "post",
            data: { serviceid:serviceid,type:type },
        }).done(function(msg){
            var code = $(msg).find('#result').text();
            if(code == 0){
                btn_clicked.parent().parent().find('.contact').html('<span class="text-success">προκαθορισμένο</span>');
                $('#message').html('<div class="alert alert-success">Η αλλαγή αποθηκεύτηκε.</div>');
            } else {
                $('#message').html('<div class="alert alert-danger">Κάτι πήγε στραβά.</div>');
            }
            $(btn_clicked).parent().toggleClass('hidden');
        });
    });
    $(document).on('click', '.contact_btn', function()
    {
        $('#contact_company_name').html($(this).data('company_name'));
        $('#modal_company_name').val($(this).data('company_name'));
        $('#modal_gr_viesvat').val($(this).data('gr_vatno'));
        $('#modal_poli').val($(this).data('city'));
        $('#modal_country').val($(this).data('country'));
        $('#modal_vies_vatno').val($(this).data('vies_vatno'));
        $('#modal_tax_office').val($(this).data('tax_office'));
        $('#modal_address').val($(this).data('address1'));
        $('#modal_address2').val($(this).data('address2'));
        $('#modal_email').val($(this).data('email'));
        $('#modal_postal_code').val($(this).data('postal_code'));
        $('#modal_description').val($(this).data('description'));
        $('#modal_telephone').val($(this).data('telephone'));
        $('#modal_parent_email').val($(this).data('parent_email'));
        $('#modal_comments').val($(this).data('comments'));
        $('#contact_modal').modal('show'); 
    });

    $(document).on('click','button.save', function(){
        var btn_clicked = $(this);
        var type = btn_clicked.data('type');
        var serviceid = btn_clicked.data('serviceid');
        var userid = btn_clicked.data('userid');
        var selected_option = $(this).parent().children("select");
        var contact_id = selected_option.val();
        var contact_name = selected_option.find(':selected').text();
        $.ajax({
            url: "addonmodules.php?module=timologia&act=submit-change",
            type: "post",
            data: { serviceid:serviceid, contactid:contact_id,userid:userid,type:type },
         }).done(function(msg){
             var code = $(msg).find('#result').text();
             if(code == 0){
                btn_clicked.parent().parent().find('.contact').html('<span class="text-success">'+contact_name+'</span>');
                $('#message').html('<div class="alert alert-success">Η αλλαγή αποθηκεύτηκε.</div>');
             } else {
                $('#message').html('<div class="alert alert-danger">Κάτι πήγε στραβά.</div>');
             }
             $(btn_clicked).parent().toggleClass('hidden');

         });
    });
    $(document).on('click', 'button.copy_to_clip', function()
    {
        element = document.getElementById($(this).data('copy_element'));
        element.select();
        document.execCommand("copy");
    });
    
    $("#toggle_predefined").on('click', function(){
       $("#predefined_results").toggle(); 
    });

    $(document).on('click', '#btnSearch', function()
    {
        perform_search();
    });
    
    $('#txtSearch').keypress(function (e) {
        if (e.which == 13)
        {
            perform_search();
            return false;
        }
    });
    function perform_search()
    {
        var query = $('#txtSearch').val();
        if (query != '')
        {
            $("#predefined_results").slideUp("fast");
            $.ajax({
              url: "addonmodules.php?module=timologia&act=client-search&search=clients",
              type: "post",
              dataType: "html",
              data: {query:query},
            }).done(function(msg){
              //console.log($(msg).find("#client_result").text());
              var result = '<div class="well well-sm"><table class="table table-condensed table-responsive"><thead><th colspan="2"><p class="h3 text-center">Αποτελέσματα αναζήτησης</p></th></thead><tbody>';
              var result_obj = JSON.parse($(msg).find("#client_result").text());
              var arr = result_obj.clients;
              for(var i=0;i<arr.length;i++){
                var obj = arr[i];
                result += '<tr><td>'+obj['firstname']+" "+obj['lastname']+'</td><td><div class="btn-group" role="group"><button class="btn btn-default btn-sm srch" data-clid="'+obj['id']+'"><span class="glyphicon glyphicon-th-list"></span> Υπηρεσίες</button> <a class="btn btn-default btn-sm" href="addonmodules.php?module=timologia&act=view-contacts&clientid='+obj['id']+'"><span class="glyphicon glyphicon-user"></span> Επαφές</a></div></td></tr>';
              }
              
              
              
              var arr_services = result_obj.services;
              for(var i = 0; i < arr_services.length; i++)
              {
                var obj = arr_services[i];
                result += '<tr><td><div class="label label-info">'+ obj['name'] +'</div> '+obj['domain']+' από <a title="Επαφές πελάτη" class="btn btn-default btn-sm" href="addonmodules.php?module=timologia&act=view-contacts&clientid=' +obj['clid'] + '">'+ obj['firstname'] + ' ' + obj['lastname'] +'</a></td><td><div class="btn-group" role="group"><button title="Όλες οι υπηρεσίες του πελάτη" class="btn btn-default btn-sm srch" data-clid="'+obj['clid']+'"><span class="glyphicon glyphicon-th-list"></span> Υπηρεσίες</button><button type="button" title="Στοιχεία τιμολόγισης" class="btn btn-info btn-sm contact_btn" data-comments="' + obj['comments'] + '" data-country="'+obj['country']+'" data-company_name="'+obj['company_name']+'" data-postal_code="'+obj['postal_code']+'" data-city="'+obj['city']+'" data-parent_email="'+obj['parent_email']+'" data-address1="'+obj['address1']+'" data-address2="'+obj['address2']+'" data-telephone="'+obj['telephone']+'" data-gr_vatno="'+obj['gr_vatno']+'" data-vies_vatno="'+obj['vies_vatno']+'" data-city="'+obj['city']+'" data-email="'+obj['email']+'" data-description="'+obj['description']+'" data-tax_office="'+obj['tax_office']+'"><span class="glyphicon glyphicon-eye-open"></span></button></div></td></tr>';
              }
              
              var arr_domains = result_obj.domains;
              for(var i = 0; i < arr_domains.length; i++)
              {
                var obj = arr_domains[i];
                result += '<tr><td><div class="label label-primary">domain</div> '+obj['domain']+' από <a title="Επαφές πελάτη" class="btn btn-default btn-sm" href="addonmodules.php?module=timologia&act=view-contacts&clientid=' +obj['clid'] + '">'+ obj['firstname'] + ' ' + obj['lastname'] +'</a></td><td><div class="btn-group" role="group"><button title="Όλες οι υπηρεσίες του πελάτη" class="btn btn-default btn-sm srch" data-clid="'+obj['clid']+'"><span class="glyphicon glyphicon-th-list"></span> Υπηρεσίες</button><button title="Στοιχεία τιμολόγισης" type="button" class="btn btn-info btn-sm contact_btn" data-comments="' + obj['comments'] + '" data-country="'+obj['country']+'" data-company_name="'+obj['company_name']+'" data-postal_code="'+obj['postal_code']+'" data-city="'+obj['city']+'" data-parent_email="'+obj['parent_email']+'" data-address1="'+obj['address1']+'" data-address2="'+obj['address2']+'" data-telephone="'+obj['telephone']+'" data-gr_vatno="'+obj['gr_vatno']+'" data-vies_vatno="'+obj['vies_vatno']+'" data-city="'+obj['city']+'" data-email="'+obj['email']+'" data-description="'+obj['description']+'" data-tax_office="'+obj['tax_office']+'"><span class="glyphicon glyphicon-eye-open"></span></button> </div></td></tr>';
              }
              
              result += '</tbody></table></div>';
              $('#results').slideUp("fast",function(){
                $('#results').html(result);
              });
              $('#results').slideDown("slow");
            });
        }
    }
   // var wto;
    // $("#txtSearch").on('input',function(){ //fetch clients based on query
    //   clearTimeout(wto);
    //   var query = $(this).val();
    //   $("#predefined_results").slideUp("fast");
    //   wto = setTimeout(function(){
    //     $.ajax({
    //       url: "addonmodules.php?module=timologia&act=client-search&search=clients",
    //       type: "post",
    //       dataType: "html",
    //       data: {query:query},
    //     }).done(function(msg){
    //       //console.log($(msg).find("#client_result").text());
    //       var result = '<div class="well well-sm"><table class="table table-condensed table-responsive"><thead><th colspan="2"><p class="h3 text-center">Αποτελέσματα αναζήτησης</p></th></thead><tbody>'
    //       var arr = JSON.parse($(msg).find("#client_result").text());
    //       for(var i=0;i<arr.length;i++){
    //         var obj = arr[i];
    //         result += '<tr><th>'+obj['firstname']+" "+obj['lastname']+'</th><td><div class="btn-group" role="group"><button class="btn btn-default btn-sm srch" data-clid="'+obj['id']+'"><span class="glyphicon glyphicon-th-list"></span> Υπηρεσίες</button> <a class="btn btn-default btn-sm" href="addonmodules.php?module=timologia&act=view-contacts&clientid='+obj['id']+'"><span class="glyphicon glyphicon-user"></span> Επαφές</a></div></td></tr>';
    //       }
    //       result += '</tbody></table></div>';
    //       $('#results').slideUp("fast",function(){
    //         $('#results').html(result);
    //       });
    //       $('#results').slideDown("slow");
    //     });
    //   }, 300); //0,3 second
    // });
  });
</script>
