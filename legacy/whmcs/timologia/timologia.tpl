{include file='modules/addons/timologia/nav.tpl'}
<div class="col-md-10">
  <div class="col-md-12">
    <div class="panel panel-default">
      <div class="panel-heading text-center">
        <h3>Οι υπηρεσίες μου</h3>
      </div>
      <div class="panel-body">
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
        <div id="message"></div>
        <table class="table table-striped table-condensed table-responsive">
          <thead>
            <tr>
                <th>Υπηρεσία</th>
                <th>Επαφή</th>
                <th>Ενέργειες</th>
                <th>Τύπος</th>
            </tr>
          </thead>
          <tbody>
            {foreach from=$hosting item=$h}
              <tr>
                <td>{$h.domain}<div class="label label-info">{$h.type}</div></td>
                <td>
                  {if empty($h.onoma)}
                    προκαθορισμένο
                  {else}
                    {$h.onoma}
                  {/if}
                </td>
                <td>
                  {if empty($contacts)}
                    δεν βρέθηκαν επαφές, <a href="/clients/index.php?m=timologia&act=contacts" class="btn btn-default">προσθέστε μία</a>
                  {else}
                    <div style="display:none" id="divh{$h.serviceid}">
                      <select id="addHosting{$h.serviceid}">
                        {foreach from=$contacts item=$c}
                          <option value="{$c.id}">{$c.onoma}</option>
                        {/foreach}
                      </select>
                      <button class="btn btn-primary btn-xs addService" data-serviceid="{$h.serviceid}" data-servicetype="hosting"><span class="glyphicon glyphicon-floppy-disk"></span> Ενημέρωση</button>
                      <button class="btn btn-default btn-xs resetService" data-sid="{$h.serviceid}" data-servicetype="hosting"><span class="glyphicon glyphicon-repeat"></span> επαναφορά σε προκαθορισμένο</button>
                    </div>
                    <button class="btn btn-xs btn-primary changeh" data-hid="{$h.serviceid}"><span class="glyphicon glyphicon-edit"></span> Αλλαγή</button>
                  {/if}
                </td>
                <td>
                    {if !empty($h.onoma)}
                    <button data-domain="{$h.domain}" data-timologio_id="{$h.timologio_id}" data-is_receipt="{$h.is_receipt}" class="btn btn-xs btn-default btnEditType">
                        {if $h.is_receipt == true}
                            Απόδειξη
                        {else}
                            Τιμολόγιο
                        {/if}
                    </button>
                    {/if}
                </td>
              </tr>
            {/foreach}
            {foreach from=$domains item=$d}
              <tr>
                <td>{$d.domain}<div class="label label-info">Domain</div></td>
                <td>
                  {if empty($d.onoma)}
                    προκαθορισμένο
                  {else}
                     {$d.onoma}
                  {/if}
                </td>
                <td>
                  {if empty($contacts)}
                    δεν βρέθηκαν επαφές, <a href="/clients/index.php?m=timologia&act=contacts" class="btn btn-default">προσθέστε μία</a>
                  {else}
                    <div style="display:none" id="divd{$d.serviceid}">
                      <select id="addDomain{$d.serviceid}">
                        {foreach from=$contacts item=$c}
                          <option value="{$c.id}">{$c.onoma}</option>
                        {/foreach}
                      </select>
                      <button class="btn btn-primary btn-xs addService" data-serviceid="{$d.serviceid}" data-servicetype="domain"><span class="glyphicon glyphicon-floppy-disk"></span> Ενημέρωση</button>
                      <button class="btn btn-default btn-xs resetService" data-sid="{$d.serviceid}" data-servicetype="domain"><span class="glyphicon glyphicon-repeat"></span> επαναφορά σε προκαθορισμένο</button>
                    </div>
                    <button class="btn btn-xs btn-primary changed" data-did="{$d.serviceid}"><span class="glyphicon glyphicon-edit"></span> Αλλαγή</button>
                  {/if}
                </td>
                <td>{if !empty($d.onoma)}
                        <button data-domain="{$d.domain}" data-timologio_id="{$d.timologio_id}" data-is_receipt="{$d.is_receipt}" class="btn btn-xs btn-default btnEditType">
                        {if $d.is_receipt == true}
                            Απόδειξη
                        {else}
                            Τιμολόγιο
                        {/if}
                        </button>
                    {/if}
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
      <div class="panel-footer">
          <div><em>(*)προκαθορισμένο = ο δικός σας λογαριασμός</em></div>
      </div>
    </div>
  </div>
</div>

  <script type="text/javascript">
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
    $(document).ready(function() {
        $(document).on('click', 'button.btnEditType', function()
        {
            $('#timologio_type_modal_message').html('');
            $('#timologio_type_modal_title').html($(this).data('domain'));
            $('#timologio_type_modal_id').val($(this).data("timologio_id"));
            if ($(this).data('is_receipt') == 0 || $(this).data('is_receipt') == null || $(this).data('is_receipt') == "0")
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
                url: "/clients/index.php?m=timologia&act=set-invType",
                type: "post",
                data: { timologio_id:id,isReceipt:isReceipt },
            }).done(function(msg){
                var code = $(msg).find('#result').text();
                $('#timologio_type_modal').modal('hide'); 
                if(code == 0){
                    $("#message").slideUp();
                    $("#message").html('<div class="alert alert-success">Η αλλαγή αποθηκεύτηκε!</div>');
                    $("#message").slideDown();
                    $('button.btnEditType[data-timologio_id="'+id+'"]').html(invType(isReceipt));
                    $('button.btnEditType[data-timologio_id="'+id+'"]').data('is_receipt', isReceipt);
                } else {
                    $("#message").slideUp();
                    $("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά. Παρακαλώ επικοινωνήστε μαζί μας.</div>');
                    $("#message").slideDown();
                }
            });
        });
      $(".resetService").click(function() {
        button = $(this);
        var serviceid = $(this).data("sid");
        var servicetype = $(this).data("servicetype");
        $.ajax({
          url: "/clients/index.php?m=timologia&act=reset-service",
          type: "post",
          data: { serviceid:serviceid, servicetype:servicetype },
        }).done(function(msg) {
          if (msg==0) {
            button.parent().parent().parent().children("td:eq(1)").html('<p class="text-success">προκαθορισμένο</p>');
            $("#message").slideUp();
            $("#message").html('<div class="alert alert-success">Η αλλαγή αποθηκεύτηκε!</div>');
            $("#message").slideDown();
          } else {
            $("#message").slideUp();
            $("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά. Παρακαλώ επικοινωνήστε μαζί μας.</div>');
            $("#message").slideDown();
          }
        });
        button.parent().fadeOut();
        button.parent().next().fadeIn();
      });
      $(".changeh").click(function () {
        var divid = "divh" + $(this).data("hid");
        $(this).fadeOut("fast", function(){
          $("#"+divid).fadeIn("slow");
        });
      });
      $(".changed").click(function () {
        var divid = "divd" + $(this).data("did");
        $(this).fadeOut("fast", function(){
          $("#"+divid).fadeIn("slow");
        });
      });
      $("#timologia_menu_parastatika").addClass("active");

      $(".addService").click(function (){
        var btn = $(this);
        var serviceid = $(this).data("serviceid");
        var servicetype = $(this).data("servicetype");
        if ( servicetype == 'hosting' ) {
          var contactid = $("#addHosting"+serviceid).val();
          var name = $("#addHosting"+serviceid+" option:selected").text();
        } else if ( servicetype == 'domain' ) {
          var contactid = $("#addDomain"+serviceid).val();
          var name = $("#addDomain"+serviceid+" option:selected").text();
        }
        $.ajax({
          url: "index.php?m=timologia&act=add-service",
          type: "post",
          data: { serviceid:serviceid,servicetype:servicetype,contactid:contactid },
        }).done(function(msg) {
          if(msg=="0"){
            btn.parent().parent().parent().children("td:eq(1)").html('<p class="text-success">'+name+'</p>');
            $("#message").slideUp();
            $("#message").html('<div class="alert alert-success">Η αλλαγή αποθηκεύτηκε!</div>');
            $("#message").slideDown();
          } else {
            $("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά, '+msg+'</div>');
          }
        });
        btn.parent().fadeOut();
        btn.parent().next().fadeIn();
      });


      $("#addHosting").click(function() {
        var hosting_id = $("#hosting").val();
        var hosting_contact = $("#hosting_contact").val();
        $("#hosting option:selected").remove();
        if($("#hosting").val() == null){
          $("#addHosting").attr("disabled",true);
        }
        $.ajax({
          url: "/clients/index.php?m=timologia&act=add-hosting",
          type: "post",
          data: { hosting_id:hosting_id, hosting_contact:hosting_contact },
        }).done(function(msg){
          if(msg=="1"){
            $("#hosting_message").html("<div class='alert alert-success'>Η επιλογή σας αποθηκεύτηκε</div>");
          } else {
            $("#hosting_message").html("<div class='alert alert-danger'>Κάτι πήγε στραβά: "+msg+"</div>");
          }
        });
      });//hosting

      $("#addDomain").click(function() {
        var domain_id = $("#domain").val();
        var domain_contact = $("#domain_contact").val();
        $("#domain option:selected").remove();
        if($("#domain").val() == null){
          $("#addDomain").attr("disabled",true);
        }
        $.ajax({
          url: "/clients/index.php?m=timologia&act=add-domain",
          type: "post",
          data: { domain_id:domain_id, domain_contact:domain_contact },
        }).done(function(msg){
          alert(msg);
        });
      });//domains

    });
  </script>
