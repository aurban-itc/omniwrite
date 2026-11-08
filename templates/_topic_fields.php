<?php /** Felder eines Themas. Erwartet $old (Array), $errors, $e, $t. */ ?>
<label for="title"><?= $e($t('topic.title')) ?></label>
<input id="title" name="title" required maxlength="255" value="<?= $e($old['title'] ?? '') ?>">
<?php if (!empty($errors['title'])): ?><p class="err"><?= $e($t($errors['title'])) ?></p><?php endif; ?>
<label for="keywords"><?= $e($t('post.keywords')) ?></label>
<input id="keywords" name="keywords" maxlength="400" value="<?= $e($old['keywords'] ?? '') ?>">
<label for="category"><?= $e($t('post.category')) ?></label>
<input id="category" name="category" maxlength="80" value="<?= $e($old['category'] ?? '') ?>">
<label for="notes"><?= $e($t('topic.notes')) ?></label>
<textarea id="notes" name="notes" rows="5"><?= $e($old['notes'] ?? '') ?></textarea>
