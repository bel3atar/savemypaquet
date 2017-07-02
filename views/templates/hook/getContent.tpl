{if isset($confirmation)}
  <div class="alert alert-success">Identifiants enregistrés</div>
{/if}

<form action="" method="POST">
  <div class="form-group">
    <label for="SMP_LOGIN">Nom d'utilisateur</label>
    <input id="SMP_LOGIN" class="form-control" name="SMP_LOGIN" value="{$SMP_LOGIN}" />
  </div>
  <div class="form-group">
    <label for="SMP_PASSWORD">Mot de passe</label>
    <input id="SMP_PASSWORD" class="form-control" name="SMP_PASSWORD" value="{$SMP_PASSWORD}" />
  </div>
  <button name="savemypaquet_form_submit" type="submit" class="btn btn-default">Enregistrer</button>
</form>
