<?php
/** @var array $city */
/** @var string $metro */
/** @var array $errors */
$slug = (string)($city['slug'] ?? '');
$displayName = (string)($city['display_name'] ?? $city['name'] ?? $slug);
$bc = $city['body_copy'] ?? null;
$hasPage = page_exists($slug);
$globalErrors = global_errors($errors);

header_html("Edit: $displayName", $metro);
?>
<section class="page-head">
  <div>
    <h1><?= e($displayName) ?> <span class="muted">/ <?= e($slug) ?></span></h1>
    <p class="muted"><a href="<?= e(admin_url(['metro' => $metro])) ?>">← All cities</a></p>
  </div>
  <div class="action-bar">
    <form method="post" action="<?= e(admin_url(['action' => 'generate', 'metro' => $metro, 'slug' => $slug])) ?>" style="display:inline">
      <button class="btn" <?= empty($bc) ? 'disabled title="Run agent first"' : '' ?>>Generate page</button>
    </form>
    <a class="btn"
       href="<?= e(admin_url(['action' => 'agent-stream-page', 'metro' => $metro, 'slug' => $slug])) ?>">
       Run agent
    </a>
    <?php if ($hasPage): ?>
      <a class="btn" href="<?= e('/public_html/' . $slug . '/') ?>" target="_blank">Preview ↗</a>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($globalErrors)): ?>
  <div class="flash flash-err">
    <strong>Validator:</strong>
    <ul>
      <?php foreach ($globalErrors as $msg): ?>
        <li><?= e($msg) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(admin_url(['action' => 'save', 'metro' => $metro, 'slug' => $slug])) ?>" class="city-form">
  <fieldset>
    <legend>Identity</legend>
    <?php field_text('name', 'Name', (string)($city['name'] ?? ''), $errors); ?>
    <?php field_text('display_name', 'Display name', (string)($city['display_name'] ?? ''), $errors); ?>
    <?php field_text('parent_county', 'Parent county', (string)($city['parent_county'] ?? ''), $errors); ?>
    <?php field_text('parent_municipality', 'Parent municipality', (string)($city['parent_municipality'] ?? ''), $errors); ?>
  </fieldset>

  <fieldset>
    <legend>Geography</legend>
    <?php field_repeat('zips', 'ZIPs', $city['zips'] ?? [], $errors, [
      'placeholder' => '30305',
      'pattern' => '\d{5}',
      'inputmode' => 'numeric',
      'min_rows' => 1,
    ]); ?>
    <div class="grid-3">
      <?php field_number('population', 'Population', $city['population'] ?? null, $errors); ?>
      <?php field_number('median_home_age', 'Median home age', $city['median_home_age'] ?? null, $errors); ?>
      <?php field_number('homeownership_rate', 'Homeownership rate (0–1)', $city['homeownership_rate'] ?? null, $errors, ['step' => '0.01', 'min' => '0', 'max' => '1']); ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Vibe & Landmarks</legend>
    <?php field_textarea('vibe', 'Vibe (≤140 chars)', (string)($city['vibe'] ?? ''), $errors, ['maxlength' => 140, 'rows' => 2, 'counter' => true]); ?>
    <?php field_repeat('notable_landmarks', 'Notable landmarks (3–5)', $city['notable_landmarks'] ?? [], $errors, [
      'min_rows' => 3,
    ]); ?>
  </fieldset>

  <fieldset>
    <legend>Service relevance</legend>
    <table class="sr-table">
      <thead><tr><th>Service</th><th>Score (1–5)</th><th>Why</th></tr></thead>
      <tbody>
        <?php foreach (['electrical', 'hvac', 'plumbing', 'handyman'] as $svc):
          $entry = $city['service_relevance'][$svc] ?? ['score' => 3, 'why' => ''];
          $err = field_error($errors, "service_relevance.$svc");
        ?>
          <tr<?= $err ? ' class="has-error"' : '' ?>>
            <th><?= e($svc) ?></th>
            <td>
              <select name="sr_score[<?= e($svc) ?>]">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <option value="<?= $i ?>"<?= ((int)($entry['score'] ?? 3) === $i) ? ' selected' : '' ?>><?= e(score_label($i)) ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <input type="text" name="sr_why[<?= e($svc) ?>]" value="<?= e((string)($entry['why'] ?? '')) ?>" />
            </td>
          </tr>
          <?php if ($err): ?>
            <tr class="error-row"><td colspan="3"><span class="error-pill"><?= e($err) ?></span></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </fieldset>

  <fieldset>
    <legend>Local CTAs (exactly 5, ≤90 chars each)</legend>
    <?php
    $ctas = $city['local_ctas'] ?? [];
    $ctas = array_pad(array_slice($ctas, 0, 5), 5, '');
    foreach ($ctas as $i => $cta):
        $err = field_error($errors, 'local_ctas');
    ?>
      <div class="row<?= $err ? ' has-error' : '' ?>">
        <input type="text" name="local_ctas[]" maxlength="90" value="<?= e((string)$cta) ?>"
               placeholder="CTA <?= $i + 1 ?> of 5" />
      </div>
    <?php endforeach; ?>
    <?php if ($err = field_error($errors, 'local_ctas')): ?>
      <div class="error-pill"><?= e($err) ?></div>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>Content hooks (2–3)</legend>
    <?php field_repeat('content_hooks', 'Hooks', $city['content_hooks'] ?? [], $errors, ['min_rows' => 2]); ?>
  </fieldset>

  <fieldset>
    <legend>SEO</legend>
    <?php field_text('seo_h1', 'H1', (string)($city['seo']['h1'] ?? ''), $errors, [], 'seo.h1'); ?>
    <?php field_text('seo_title_tag', 'Title tag (≤60)', (string)($city['seo']['title_tag'] ?? ''), $errors, ['maxlength' => 60, 'counter' => true], 'seo.title_tag'); ?>
    <?php field_textarea('seo_meta_description', 'Meta description (≤160)', (string)($city['seo']['meta_description'] ?? ''), $errors, ['maxlength' => 160, 'rows' => 3, 'counter' => true], 'seo.meta_description'); ?>
    <?php field_repeat('seo_primary_keywords', 'Primary keywords (3–6)', $city['seo']['primary_keywords'] ?? [], $errors, ['min_rows' => 3], 'seo.primary_keywords'); ?>
  </fieldset>

  <fieldset>
    <legend>Sources (≥2 https URLs)</legend>
    <?php field_repeat('sources', 'Sources', $city['sources'] ?? [], $errors, [
      'placeholder' => 'https://...',
      'inputmode'   => 'url',
      'min_rows'    => 2,
    ]); ?>
  </fieldset>

  <fieldset>
    <legend>Review notes</legend>
    <?php field_textarea('review_notes', 'Notes (free text)', (string)($city['review_notes'] ?? ''), $errors, ['rows' => 2]); ?>
  </fieldset>

  <fieldset class="body-copy">
    <legend>Body copy (managed by agent)</legend>
    <?php if ($bc): ?>
      <details>
        <summary>intro · <?= word_count($bc['intro'] ?? '') ?> words</summary>
        <p><?= e((string)($bc['intro'] ?? '')) ?></p>
      </details>
      <?php foreach (($bc['sections'] ?? []) as $i => $sec): ?>
        <details>
          <summary>§ <?= e((string)($sec['h2'] ?? '')) ?> · <?= word_count($sec['body'] ?? '') ?> words</summary>
          <p><?= e((string)($sec['body'] ?? '')) ?></p>
        </details>
      <?php endforeach; ?>
      <details>
        <summary>areas_served · <?= word_count($bc['areas_served'] ?? '') ?> words</summary>
        <p><?= e((string)($bc['areas_served'] ?? '')) ?></p>
      </details>
      <details>
        <summary>closing_cta · <?= word_count($bc['closing_cta'] ?? '') ?> words</summary>
        <p><?= e((string)($bc['closing_cta'] ?? '')) ?></p>
      </details>
      <p class="muted small">Re-run the agent to regenerate this content.</p>
    <?php else: ?>
      <p class="muted">No body copy yet. Click <strong>Run agent</strong> above to generate it via the city-content-writer agent.</p>
    <?php endif; ?>
  </fieldset>

  <div class="form-actions">
    <button type="submit" class="btn-primary">Save</button>
    <a href="<?= e(admin_url(['metro' => $metro])) ?>" class="btn">Cancel</a>
  </div>
</form>

<?php
footer_html();

// ----- field helpers ------------------------------------------------------

function field_text(string $name, string $label, string $value, array $errors, array $opts = [], ?string $errKey = null): void
{
    $errKey = $errKey ?? $name;
    $err = field_error($errors, $errKey);
    $maxlength = isset($opts['maxlength']) ? (int)$opts['maxlength'] : null;
    $counter   = !empty($opts['counter']);
    ?>
    <label class="field<?= $err ? ' has-error' : '' ?>">
      <span><?= e($label) ?></span>
      <input type="text" name="<?= e($name) ?>" value="<?= e($value) ?>"
        <?= $maxlength ? 'maxlength="' . $maxlength . '"' : '' ?>
        <?= $counter ? 'data-counter="1"' : '' ?> />
      <?php if ($counter): ?>
        <span class="counter"><span class="counter-current"><?= mb_strlen($value) ?></span>/<?= $maxlength ?? '?' ?></span>
      <?php endif; ?>
      <?php if ($err): ?><span class="error-pill"><?= e($err) ?></span><?php endif; ?>
    </label>
    <?php
}

function field_number(string $name, string $label, mixed $value, array $errors, array $opts = []): void
{
    $err = field_error($errors, $name);
    $step = $opts['step'] ?? '1';
    $min  = $opts['min'] ?? null;
    $max  = $opts['max'] ?? null;
    ?>
    <label class="field<?= $err ? ' has-error' : '' ?>">
      <span><?= e($label) ?></span>
      <input type="number" name="<?= e($name) ?>"
        value="<?= $value === null ? '' : e((string)$value) ?>"
        step="<?= e($step) ?>"
        <?= $min !== null ? 'min="' . e($min) . '"' : '' ?>
        <?= $max !== null ? 'max="' . e($max) . '"' : '' ?> />
      <?php if ($err): ?><span class="error-pill"><?= e($err) ?></span><?php endif; ?>
    </label>
    <?php
}

function field_textarea(string $name, string $label, string $value, array $errors, array $opts = [], ?string $errKey = null): void
{
    $errKey = $errKey ?? $name;
    $err = field_error($errors, $errKey);
    $maxlength = isset($opts['maxlength']) ? (int)$opts['maxlength'] : null;
    $rows      = (int)($opts['rows'] ?? 3);
    $counter   = !empty($opts['counter']);
    ?>
    <label class="field<?= $err ? ' has-error' : '' ?>">
      <span><?= e($label) ?></span>
      <textarea name="<?= e($name) ?>" rows="<?= $rows ?>"
        <?= $maxlength ? 'maxlength="' . $maxlength . '"' : '' ?>
        <?= $counter ? 'data-counter="1"' : '' ?>><?= e($value) ?></textarea>
      <?php if ($counter): ?>
        <span class="counter"><span class="counter-current"><?= mb_strlen($value) ?></span>/<?= $maxlength ?? '?' ?></span>
      <?php endif; ?>
      <?php if ($err): ?><span class="error-pill"><?= e($err) ?></span><?php endif; ?>
    </label>
    <?php
}

function field_repeat(string $name, string $label, array $values, array $errors, array $opts = [], ?string $errKey = null): void
{
    $errKey = $errKey ?? $name;
    $err = field_error($errors, $errKey);
    $min = (int)($opts['min_rows'] ?? 1);
    $placeholder = (string)($opts['placeholder'] ?? '');
    $pattern = $opts['pattern'] ?? null;
    $inputmode = $opts['inputmode'] ?? null;

    $values = array_values($values);
    while (count($values) < $min) $values[] = '';
    ?>
    <div class="field repeat<?= $err ? ' has-error' : '' ?>" data-repeat="<?= e($name) ?>">
      <span class="label-row">
        <span><?= e($label) ?></span>
        <button type="button" class="btn-tiny" data-repeat-add>+ add</button>
      </span>
      <div class="repeat-rows">
        <?php foreach ($values as $v): ?>
          <div class="repeat-row">
            <input type="text" name="<?= e($name) ?>[]" value="<?= e((string)$v) ?>"
              <?= $placeholder !== '' ? 'placeholder="' . e($placeholder) . '"' : '' ?>
              <?= $pattern ? 'pattern="' . e($pattern) . '"' : '' ?>
              <?= $inputmode ? 'inputmode="' . e($inputmode) . '"' : '' ?> />
            <button type="button" class="btn-tiny remove" data-repeat-remove>×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($err): ?><span class="error-pill"><?= e($err) ?></span><?php endif; ?>
    </div>
    <?php
}
?>
