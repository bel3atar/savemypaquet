<div style="max-width: 100%; padding:10px">
  {if $phone eq ''}
    <span style="color:red">Merci d'indiquer votre numéro de téléphone portable, étage, position de votre porte dans "complément d'adresse"</span><br>
  {/if}
  <img style="max-width: 100%" src="./modules/savemypaquet/views/templates/hook/smpfilet.png">
</div>
<script>
  if ('{$phone}' === '') {
    document.addEventListener('DOMContentLoaded', function () {
      var ids = JSON.parse('{$smpids nofilter}');
      var btn = $('button[name=confirmDeliveryOption]').first();
      ids.forEach(function (id) {
        var el = $('#delivery_option_' + id).first();
        if (el.attr('checked') === 'checked') btn.prop('disabled', true);
      });
      $('input[type=radio][name^=delivery_option]').change(function (ev) {
          btn.prop('disabled', ids.map(function(x) { return 'delivery_option_' + x }).indexOf(ev.target.id) !== -1);
      });
    });
  }
</script>
