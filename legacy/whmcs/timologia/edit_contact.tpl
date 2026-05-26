{include file='modules/addons/timologia/nav.tpl'}
{if $code==1}
  <div class="col-md-10">
    <div class="alert alert-warning">Κάτι πήγε στραβά!</div>
  </div>
{else}
<div class="col-md-10">
  <div class="col-md-12" id="message"></div>
  <div class="col-md-6 form-group">
    <input type="hidden" id="id" onoma="id" value="{$contact[0]->id}">
    <input class="form-control" type="text" name="onoma" id="onoma" placeholder="Επωνυμία" value="{$contact[0]->company_name}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="poli" id="poli" placeholder="Πόλη" value="{$contact[0]->city}">
  </div>
  <div class="col-md-6 form-group">
    <input type="text" name="address" id="address" placeholder="Διεύθυνση" class="form-control" value="{$contact[0]->address1}">
  </div>
  <div class="col-md-6 form-group">
    <input type="text" name="address_2" id="address_2" placeholder="Διεύθυνση 2" class="form-control" value="{$contact[0]->address2}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="postal_code" id="postal_code" placeholder="Τ.Κ." value="{$contact[0]->postal_code}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="doy" id="doy" placeholder="ΔΟΥ" value="{$contact[0]->tax_office}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="country" id="country" placeholder="Χώρα" value="{$contact[0]->country}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="afm" id="afm" placeholder="ΑΦΜ" value="{$contact[0]->gr_vatno}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="vies_vatno" id="vies_vatno" placeholder="VIES Vat Number" value="{$contact[0]->vies_vatno}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="drastiriotita" id="drastiriotita" placeholder="Δραστηριότητα" value="{$contact[0]->description}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="email" id="email" placeholder="E-mail" value="{$contact[0]->email}">
  </div>
  <div class="col-md-6 form-group">
    <input class="form-control" type="text" name="telephone_" id="telephone_" placeholder="Τηλέφωνο" value="{$contact[0]->telephone}">
  </div>
  <div class="col-md-12 form-group">
    <textarea class="form-control" id="comments" name="comments" placeholder="Σχόλια (προαιρετικό)">{$contact[0]->comments}</textarea>
  </div>
  <div class="col-md-12 form-group text-center">
    <button class="btn btn-primary" id="submit"><span class="glyphicon glyphicon-floppy-disk"></span> Ενημέρωση επαφής</button>
  </div>
</div>
<script>
$(document).ready(function() {
  $("#timologia_menu_epafes").addClass("active");
  $("#submit").click(function() {
    var onoma = $("#onoma").val();
    var poli = $("#poli").val();
    var address = $("#address").val();
    var doy = $("#doy").val();
    var afm = $("#afm").val();
    var drastiriotita = $("#drastiriotita").val();
    var email = $("#email").val();
    var comments = $("#comments").val();
    var address_2 = $("#address_2").val();
    var vies_vatno = $("#vies_vatno").val();
    var country = $("#country").val();
    var id = $("#id").val();
    var postal_code = $("#postal_code").val();
    var telephone = $("#telephone_").val();
    if ( onoma == "" || poli == "" || doy == "" || afm == "" || drastiriotita == "" || address == "")
    {
      alert("Παρακαλώ συμπληρώστε τα απαραίτητα πεδία.")
    } else {
      $.ajax({
        url: "/clients/index.php?m=timologia&act=submit-edit-contact",
        type: "post",
        data: { contactid:id,company_name:onoma,city:poli,address1:address,tax_office:doy,gr_vatno:afm,description:drastiriotita,email:email,comments:comments,address_2:address_2,vies_vatno:vies_vatno,country:country,postal_code:postal_code,telephone:telephone },
      }).done(function(msg){
        if(msg==0){
          $("#message").slideUp("fast");
          $("#message").html('<div class="alert alert-success">Η επαφή ενημερώθηκε!</div>');
          $("#message").slideDown("slow");
        } else {
          $("#message").html('<div class="alert alert-danger">Κάτι πήγε στραβά!</div>');
        }
      });
    }
  });
});
</script>
{/if}
