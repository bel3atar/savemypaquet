<div style="max-width: 100%; padding:10px">
{if $phone eq ''}
  <span style="color:red">Merci d'indiquer votre numéro de téléphone portable, étage, position de votre porte dans "complément d'adresse"</span><br>
{/if}
<img style="max-width: 100%" src="./modules/savemypaquet/views/templates/hook/smpfilet.png">
<input type="button" id=smpbtn value="Hello" />
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var el = $('#delivery_option_{$smpid}').first(),
      btn = $('button[name=confirmDeliveryOption]').first();
    if (el.attr('checked') && '{$phone}' === '') btn.prop('disabled', true);
    $('input[type=radio][name^=delivery_option]').change(function (ev) {
      btn.prop('disabled', ev.target.id === 'delivery_option_{$smpid}' && '{$phone}' === '');
    });
  });
</script>
</div>
