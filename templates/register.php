<h1><?= $e($t('register.title')) ?></h1>
<form method="post" action="/register">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php foreach ([['name','field.name','text','name'],['account_name','field.account_name','text','organization'],['email','field.email','email','username'],['password','field.password','password','new-password']] as [$f,$label,$type,$ac]): ?>
    <label for="<?= $f ?>"><?= $e($t($label)) ?></label>
    <input id="<?= $f ?>" name="<?= $f ?>" type="<?= $type ?>" autocomplete="<?= $ac ?>" required<?= $type !== 'password' ? ' value="' . $e($old[$f] ?? '') . '"' : '' ?>>
    <?php if (!empty($errors[$f])): ?><p class="err"><?= $e($t($errors[$f])) ?></p><?php endif; ?>
  <?php endforeach; ?>
  <button><?= $e($t('btn.register')) ?></button>
</form>
