// Repeatable rows
document.addEventListener('click', (e) => {
  const addBtn = e.target.closest('[data-repeat-add]');
  if (addBtn) {
    const wrap = addBtn.closest('[data-repeat]');
    const rows = wrap.querySelector('.repeat-rows');
    const last = rows.querySelector('.repeat-row');
    const next = last ? last.cloneNode(true) : buildEmptyRow(wrap.dataset.repeat);
    next.querySelectorAll('input').forEach((i) => (i.value = ''));
    rows.appendChild(next);
    next.querySelector('input')?.focus();
  }
  const rmBtn = e.target.closest('[data-repeat-remove]');
  if (rmBtn) {
    const row = rmBtn.closest('.repeat-row');
    const wrap = rmBtn.closest('[data-repeat]');
    const rows = wrap.querySelectorAll('.repeat-row');
    if (rows.length > 1) row.remove();
    else row.querySelector('input').value = '';
  }
});

function buildEmptyRow(name) {
  const row = document.createElement('div');
  row.className = 'repeat-row';
  row.innerHTML =
    `<input type="text" name="${name}[]" value="" />` +
    `<button type="button" class="btn-tiny remove" data-repeat-remove>×</button>`;
  return row;
}

// Live char counters for [data-counter] inputs/textareas
document.querySelectorAll('[data-counter]').forEach((el) => {
  const counter = el.parentElement.querySelector('.counter-current');
  if (!counter) return;
  el.addEventListener('input', () => {
    counter.textContent = el.value.length;
  });
});
