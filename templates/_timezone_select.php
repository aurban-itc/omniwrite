<?php /** Zeitzonen-Auswahl, nach Region gruppiert. Erwartet $e; optional $tzSelected (Standard Europe/Berlin). */
$tzSelected = (string) (($tzSelected ?? '') !== '' ? $tzSelected : 'Europe/Berlin');
$tzGroups = [];
foreach (DateTimeZone::listIdentifiers() as $tzId) {
    $tzGroups[str_contains($tzId, '/') ? explode('/', $tzId, 2)[0] : 'UTC'][] = $tzId;
}
?>
<select id="timezone" name="timezone">
<?php foreach ($tzGroups as $tzRegion => $tzIds): ?>
  <optgroup label="<?= $e($tzRegion) ?>">
  <?php foreach ($tzIds as $tzId): ?>
    <option value="<?= $e($tzId) ?>"<?= $tzId === $tzSelected ? ' selected' : '' ?>><?= $e($tzId) ?></option>
  <?php endforeach; ?>
  </optgroup>
<?php endforeach; ?>
</select>
