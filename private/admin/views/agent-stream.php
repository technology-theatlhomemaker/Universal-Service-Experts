<?php
/** @var string $slug */
/** @var string $metro */
header_html("Run agent: $slug", $metro);
?>
<section class="page-head">
  <div>
    <h1>Run city-content-writer for <code><?= e($slug) ?></code></h1>
    <p class="muted">
      <a href="<?= e(admin_url(['view' => 'edit', 'metro' => $metro, 'slug' => $slug])) ?>">← Back to edit</a>
    </p>
  </div>
  <div class="action-bar">
    <button id="agent-start" class="btn-primary">Start</button>
    <button id="agent-reload" class="btn" disabled>Reload edit page</button>
  </div>
</section>

<p class="muted small">
  This shells out to <code>claude -p "Use the city-content-writer agent for <?= e($slug) ?>"</code>.
  Streamed output appears below. The agent updates <code>data/service-areas/<?= e($metro) ?>.json</code> directly.
</p>

<pre id="agent-output" class="agent-output">Click Start to invoke the agent.</pre>

<script>
(() => {
  const startBtn  = document.getElementById('agent-start');
  const reloadBtn = document.getElementById('agent-reload');
  const out       = document.getElementById('agent-output');
  const slug      = <?= json_encode($slug, JSON_UNESCAPED_SLASHES) ?>;
  const metro     = <?= json_encode($metro, JSON_UNESCAPED_SLASHES) ?>;

  startBtn.addEventListener('click', async () => {
    startBtn.disabled = true;
    out.textContent = '';

    const url = 'index.php?action=run-agent&metro=' + encodeURIComponent(metro)
              + '&slug=' + encodeURIComponent(slug);

    let resp;
    try {
      resp = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'fetch' }});
    } catch (err) {
      out.textContent = 'Failed to start: ' + err.message;
      startBtn.disabled = false;
      return;
    }
    if (!resp.ok || !resp.body) {
      out.textContent = 'Server returned ' + resp.status;
      startBtn.disabled = false;
      return;
    }

    const reader  = resp.body.getReader();
    const decoder = new TextDecoder();
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      out.textContent += decoder.decode(value, { stream: true });
      out.scrollTop = out.scrollHeight;
    }
    out.textContent += decoder.decode();
    reloadBtn.disabled = false;
    startBtn.disabled  = false;
    startBtn.textContent = 'Run again';
  });

  reloadBtn.addEventListener('click', () => {
    location.href = 'index.php?view=edit&metro=' + encodeURIComponent(metro)
                  + '&slug=' + encodeURIComponent(slug);
  });
})();
</script>

<?php footer_html(); ?>
