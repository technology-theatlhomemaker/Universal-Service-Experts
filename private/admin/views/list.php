<?php
/** @var array $data */
/** @var string $metro */
header_html('Cities — ' . $metro, $metro);

$cities = $data['cities'] ?? [];
$version = (string)($data['version'] ?? '?');
?>
<section class="page-head">
  <div>
    <h1>Service Areas: <?= e($metro) ?></h1>
    <p class="muted">version <code><?= e($version) ?></code> · <?= count($cities) ?> cities</p>
  </div>
  <details class="add-city-details">
    <summary class="btn-primary">+ Add city</summary>
    <form method="post" action="<?= e(admin_url(['action' => 'add-city', 'metro' => $metro])) ?>" class="add-city-form">
      <input type="text" name="slug" placeholder="slug (e.g. roswell)" pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
      <input type="text" name="name" placeholder="Name (e.g. Roswell)" required />
      <button class="btn-primary">Add</button>
    </form>
  </details>
</section>

<table class="cities">
  <thead>
    <tr>
      <th>Slug</th>
      <th>Name</th>
      <th>ZIPs</th>
      <th>Scores E·H·P·H</th>
      <th>Body</th>
      <th>Page</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($cities as $c):
      $sr = $c['service_relevance'] ?? [];
      $scores = sprintf(
          '%d·%d·%d·%d',
          (int)($sr['electrical']['score'] ?? 0),
          (int)($sr['hvac']['score'] ?? 0),
          (int)($sr['plumbing']['score'] ?? 0),
          (int)($sr['handyman']['score'] ?? 0)
      );
      $hasBody = !empty($c['body_copy']);
      $hasPage = page_exists((string)($c['slug'] ?? ''));
  ?>
    <tr>
      <td><code><?= e($c['slug'] ?? '') ?></code></td>
      <td><?= e($c['name'] ?? '') ?></td>
      <td class="zips"><?= e(implode(', ', $c['zips'] ?? [])) ?></td>
      <td class="scores"><?= e($scores) ?></td>
      <td>
        <?php if ($hasBody): ?>
          <span class="pill pill-ok">filled</span>
        <?php else: ?>
          <span class="pill pill-warn">empty</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($hasPage): ?>
          <a href="<?= e('/public_html/' . $c['slug'] . '/') ?>" target="_blank" class="pill pill-ok">live ↗</a>
        <?php else: ?>
          <span class="pill pill-muted">none</span>
        <?php endif; ?>
      </td>
      <td class="actions">
        <a href="<?= e(admin_url(['view' => 'edit', 'metro' => $metro, 'slug' => $c['slug']])) ?>" class="btn">Edit</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php footer_html(); ?>
