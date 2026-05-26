{include file='modules/addons/timologia/nav.tpl'}
<div class="col-md-10">
  <div class="col-md-12" id="message"></div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="onoma" id="onoma" placeholder="Επωνυμία">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="poli" id="poli" placeholder="Πόλη">
  </div>
  <div class="col-md-6 form-group">
    <input type="text" name="address" id="address" placeholder="Διεύθυνση" class="form-control">
  </div>
  <div class="col-md-6 form-group">
    <input type="text" name="address_2" id="address_2" placeholder="Διεύθυνση 2" class="form-control">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="postal_code" id="postal_code" placeholder="Τ.Κ.">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="doy" id="doy" placeholder="ΔΟΥ">
  </div>
    <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="country" id="country" placeholder="Χώρα">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="afm" id="afm" placeholder="ΑΦΜ">
  </div>
    <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="vies_vatno" id="vies_vatno" placeholder="VIES VAT Number">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="drastiriotita" id="drastiriotita" placeholder="Δραστηριότητα">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="email" id="email" placeholder="E-mail">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="telephone" id="telephone" placeholder="Τηλέφωνο">
  </div>
  <div class="col-md-12 form-group">
    <textarea class="form-control" id="comments" name="comments" placeholder="Σχόλια (προαιρετικό)"></textarea>
  </div>
  <div class="col-md-12 form-group text-center">
    <button class="btn btn-primary" id="submit"><span class="glyphicon glyphicon-floppy-disk"></span> Αποθήκευση επαφής</button>
  </div>
  <div class="col-md-12">
    <hr>
    <div class="text-center">
      <h2><span class="glyphicon glyphicon-user"></span> Οι επαφές μου</h2>
    </div>
    <div id="contacts-message"></div>
    {if !empty($contacts)}
      <table class="table table-striped table-condensed">
        <thead>
          <tr>
            <th>Όνομα</th>
            <th>ΑΦΜ</th>
            <th>Ενέργειες</th>
          </tr>
        </thead>
        <tbody>
        {foreach from=$contacts item=$contact}
          <tr>
            <td>{$contact.onoma}</td>
            <td>{$contact.afm}</td>
            <td><a href="/clients/index.php?m=timologia&act=edit-contact&id={$contact.id}" class="btn btn-primary btn-sm btn_edit" title="Επεξεργασία επαφής"><span class="glyphicon glyphicon-edit"></span></a> <a title="Διαγραφή επαφής" data-id="{$contact.id}" href="#" class="btn btn-danger btn-sm btn_delete"><span class="glyphicon glyphicon-trash"></span></a></td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    {else}
      <div class="alert alert-info">Δεν έχετε δημιουργήσει επαφές ακόμη.</div>
    {/if}
  </div>
</div>

<script type="text/javascript">
  $(document).ready(function() {
    $("#timologia_menu_epafes").addClass("active");
    $("#submit").click(function() {
      var onoma = $("#onoma").val();
      var poli = $("#poli").val();
      var address = $("#address").val();
      var address_2 = $("#address_2").val();
      var vies_vatno = $("#vies_vatno").val();
      var country = $("#country").val();
      var doy = $("#doy").val();
      var telephone = $("#telephone").val();
      var postal_code = $("#postal_code").val();
      var afm = $("#afm").val();
      var drastiriotita = $("#drastiriotita").val();
      var email = $("#email").val();
      var comments = $("#comments").val();
      if ( onoma == "" || poli == "" || doy == "" || afm == "" || drastiriotita == "" || address == "" )
      {
        alert("Παρακαλώ συμπληρώστε τα απαραίτητα πεδία.")
      } else {
        $.ajax({
          url: "/clients/index.php?m=timologia&act=add-contact",
          type: "post",
          data: { onoma:onoma,poli:poli,address:address,doy:doy,afm:afm,drastiriotita:drastiriotita,comments:comments,address_2:address_2,vies_vatno:vies_vatno,country:country,email:email,telephone:telephone,postal_code:postal_code},
        }).done(function(msg){
          if (msg=="0"){
            $("#message").html('<div class="alert alert-success">Η επαφή προστέθηκε!</div>');
            $("#onoma").val("");
            $("#poli").val("");
            $("#address").val("");
            $("#doy").val("");
            $("#afm").val("");
            $("#drastiriotita").val("");
            $("#comments").val("");
            $("#address_2").val("");
            $("#vies_vatno").val("");
            $("#country").val("");
            $("#postal_code").val("");
            $("#telephone").val("");
            $("#email").val("");
          } else {
            $("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά, error code:'+msg+'</div>');
          }
        });
      }
    });//submit
    $(".btn_delete").click(function () {
      var id = $(this).data("id");
      var tr_parent = $(this).parent().parent();
      var answer = confirm("Είστε σίγουρος/η ότι θέλετε να διαγράψετε την επαφή; Αυτομάτως θα διαγραφούν και οι προτιμήσεις σας με τα παραστατικά που σχετίζονται με την επαφή.");
      if (answer) {
        $.ajax({
          url: "/clients/index.php?m=timologia&act=delete-contact",
          type: "post",
          data: { delete_id:id },
        }).done(function(msg){
          if(msg=="1"){
            tr_parent.slideUp("fast");
            $("#contacts-message").html("<div class='alert alert-info'>Η επαφή διαγράφηκε επιτυχώς!");
          } else {

          }
        });
      }
    });//delete
  });
</script>
