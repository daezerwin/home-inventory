// Home Pantry — progressive enhancements. Every form also works without JS.

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const peso = value => '₱' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/* ---------- Navigation drawer ---------- */

function setMenu(open) {
  document.body.classList.toggle('nav-open', open);
  $$('[data-menu]').forEach(button => button.setAttribute('aria-expanded', String(open)));
}
$$('[data-menu]').forEach(button => button.addEventListener('click', () => setMenu(!document.body.classList.contains('nav-open'))));
$$('[data-close-menu]').forEach(el => el.addEventListener('click', () => setMenu(false)));
document.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });

/* ---------- Group chips: bring the selected one into view ---------- */

const chip = $('.chip-row .chip.selected');
if (chip) chip.parentElement.scrollLeft = chip.offsetLeft - chip.parentElement.clientWidth / 2 + chip.clientWidth / 2;

/* ---------- Dialogs and confirmations ---------- */

$$('[data-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.dialog)?.showModal()));
$$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
$$('dialog').forEach(dialog => dialog.addEventListener('click', event => {
  const box = dialog.getBoundingClientRect();
  const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
  if (outside && event.target === dialog) dialog.close();
}));
$$('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!confirm(form.dataset.confirm)) event.stopImmediatePropagation(), event.preventDefault();
}));

/* ---------- Toasts ---------- */

function toast(message, isError = false) {
  const region = $('.toast-region');
  if (!region || !message) return;
  const el = document.createElement('div');
  el.className = 'toast' + (isError ? ' error' : '');
  el.setAttribute('role', isError ? 'alert' : 'status');
  el.textContent = message;
  region.replaceChildren(el);
  scheduleDismiss(el);
}
function scheduleDismiss(el) {
  setTimeout(() => { el.classList.add('leaving'); setTimeout(() => el.remove(), 300); }, 3200);
  el.addEventListener('click', () => el.remove());
}
$$('.toast').forEach(scheduleDismiss);

/* ---------- Keep the scroll position across ordinary form posts ---------- */

const scrollKey = 'pantry:scroll';
document.addEventListener('submit', event => {
  const form = event.target;
  if (event.defaultPrevented || form.method.toLowerCase() !== 'post' || form.dataset.async) return;
  try { sessionStorage.setItem(scrollKey, JSON.stringify({ path: location.pathname + location.search, y: scrollY, at: Date.now() })); } catch {}
});
try {
  const saved = JSON.parse(sessionStorage.getItem(scrollKey) || 'null');
  sessionStorage.removeItem(scrollKey);
  if (saved && saved.path === location.pathname + location.search && Date.now() - saved.at < 30000) {
    history.scrollRestoration = 'manual';
    scrollTo(0, saved.y);
  }
} catch {}

/* ---------- Cart counters ---------- */

function setCartCount(count) {
  if (typeof count !== 'number') return;
  $$('[data-cart-count]').forEach(el => {
    el.textContent = count;
    if (el.classList.contains('tab-badge')) el.hidden = count === 0;
  });
}

/* ---------- Async forms ---------- */

const handlers = {
  'cart-add': {
    done(form, data) {
      $('[data-cart-qty]', form).textContent = data.quantity;
      $('button', form).classList.add('in-cart');
    },
  },

  'stock-adjust': {
    quiet: true,
    done(form, data) {
      const card = form.closest('[data-stock-card]');
      const ratio = data.quantity / Math.max(1, data.target);
      $('[data-stock-qty]', card).textContent = data.quantity;
      $('[data-stock-meter]', card).style.width = Math.min(100, ratio * 100) + '%';
      const tag = $('[data-stock-tag]', card);
      tag.classList.toggle('low', data.quantity < data.target);
      $('em', tag).textContent = data.quantity === 0 ? 'Out of stock' : data.quantity < data.target ? 'Low stock' : 'In your pantry';
      const dialogQty = $(`#stock-${form.action.match(/pantry\/(\d+)/)[1]} input[name=quantity]`);
      if (dialogQty) dialogQty.value = data.quantity;
    },
  },

  // Ticking an item is instant; the request catches up in the background.
  'cart-check': {
    optimistic(form) {
      const input = $('input[name=checked]', form);
      const nowChecked = input.value === '1';
      form.closest('[data-row]').classList.toggle('is-checked', nowChecked);
      $('button', form).setAttribute('aria-pressed', String(nowChecked));
      input.value = nowChecked ? '0' : '1';
      refreshSummary();
      return () => this.optimistic(form);
    },
  },

  'cart-qty': {
    optimistic(form, body, submitter) {
      const qtyEl = $('[data-qty]', form);
      const previous = Number(qtyEl.textContent);
      const next = Math.max(1, previous + Number(submitter?.dataset.step || 0));
      body.set('quantity', next);
      paintQty(form, next);
      return () => paintQty(form, previous);
    },
  },

  'cart-remove': {
    done(form) {
      const row = form.closest('[data-row]');
      const heading = row.previousElementSibling?.matches('.aisle') ? row.previousElementSibling : null;
      row.remove();
      if (heading && !heading.nextElementSibling?.matches('[data-row]')) heading.remove();
      refreshSummary();
    },
  },
};

function paintQty(form, qty) {
  const row = form.closest('[data-row]');
  $('[data-qty]', form).textContent = qty;
  $('button.step', form).disabled = qty <= 1;
  $('[data-line-total]', row).textContent = peso(qty * Number(row.dataset.price));
  refreshSummary();
}

function refreshSummary() {
  const rows = $$('[data-row]');
  const checked = rows.filter(row => row.classList.contains('is-checked')).length;
  const units = rows.reduce((sum, row) => sum + Number($('[data-qty]', row).textContent), 0);
  const total = rows.reduce((sum, row) => sum + Number($('[data-qty]', row).textContent) * Number(row.dataset.price), 0);
  $$('[data-checked-count]').forEach(el => el.textContent = checked);
  $$('[data-total-count]').forEach(el => el.textContent = rows.length);
  $$('[data-units]').forEach(el => el.textContent = units);
  $$('[data-grand-total]').forEach(el => el.textContent = peso(total));
  const progress = $('[data-progress]');
  if (progress) progress.style.width = (rows.length ? 100 * checked / rows.length : 0) + '%';
  const checkout = $('[data-checkout]');
  if (checkout) checkout.disabled = checked === 0;
}

document.addEventListener('submit', async event => {
  const form = event.target;
  const handler = handlers[form.dataset.async];
  if (!handler || event.defaultPrevented) return;
  event.preventDefault();

  const submitter = event.submitter;
  const body = new FormData(form);
  if (submitter?.name) body.set(submitter.name, submitter.value);
  const revert = handler.optimistic?.call(handler, form, body, submitter);

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body,
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    });
    if (response.status === 419 || response.status === 401) return location.reload();
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
    handler.done?.(form, data);
    setCartCount(data.cartCount);
    if (!handler.optimistic && !handler.quiet) toast(data.message);
  } catch (error) {
    revert?.();
    toast(navigator.onLine ? error.message : "You're offline. That change wasn't saved.", true);
  }
});

/* ---------- Shopping list: hide what's already in the cart ---------- */

const list = $('[data-shopping-list]');
const hideButton = $('[data-hide-checked]');
if (list && hideButton) {
  const apply = hide => {
    list.classList.toggle('hide-checked', hide);
    hideButton.setAttribute('aria-pressed', String(hide));
    hideButton.textContent = hide ? 'Show checked' : 'Hide checked';
  };
  let hidden = false;
  try { hidden = localStorage.getItem('pantry:hide-checked') === '1'; } catch {}
  apply(hidden);
  hideButton.addEventListener('click', () => {
    hidden = !hidden;
    apply(hidden);
    try { localStorage.setItem('pantry:hide-checked', hidden ? '1' : '0'); } catch {}
  });
}

/* ---------- Admin: live photo download progress ---------- */

const statusPanel = $('[data-photo-status]');
if (statusPanel) {
  const poll = async () => {
    try {
      const data = await (await fetch(statusPanel.dataset.photoStatus, { headers: { Accept: 'application/json' } })).json();
      for (const [key, value] of Object.entries(data)) {
        $$(`[data-status="${key}"]`, statusPanel).forEach(el => el.textContent = Number(value).toLocaleString('en-US'));
      }
      if (data.queued > 0) setTimeout(poll, 5000);
    } catch {}
  };
  if (Number($('[data-status="queued"]', statusPanel).textContent.replace(/,/g, '')) > 0) setTimeout(poll, 5000);
}

/* ---------- Parallax: hero photos drift at a third of the scroll speed ---------- */

const parallaxLayers = $$('[data-parallax]');
if (parallaxLayers.length && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
  let queued = false;
  const update = () => {
    queued = false;
    for (const layer of parallaxLayers) {
      const box = layer.parentElement.getBoundingClientRect();
      if (box.bottom < 0 || box.top > innerHeight) continue;
      layer.style.transform = `translate3d(0, ${(-box.top * 0.35).toFixed(1)}px, 0)`;
    }
  };
  const queue = () => { if (!queued) { queued = true; requestAnimationFrame(update); } };
  addEventListener('scroll', queue, { passive: true });
  addEventListener('resize', queue);
  update();
}

/* ---------- Recipe ingredient picker ---------- */

const picker = $('#ingredient-picker');
if (picker) {
  const query = $('[data-picker-query]', picker);
  const results = $('[data-picker-results]', picker);
  let searchTimer;
  let lastSearch = 0;

  const resultButton = product => {
    const button = document.createElement('button');
    button.className = 'picker-result';
    button.name = 'product_id';
    button.value = product.id;
    const thumb = document.createElement('span');
    thumb.className = 'thumb';
    if (product.image) {
      const img = document.createElement('img');
      img.src = product.image;
      img.alt = '';
      thumb.append(img);
    }
    const text = document.createElement('span');
    text.className = 'match-product';
    const name = document.createElement('b');
    name.textContent = product.name;
    const details = document.createElement('small');
    details.textContent = [product.size, product.category].filter(Boolean).join(' · ');
    text.append(name, details);
    const price = document.createElement('span');
    price.className = 'price';
    price.textContent = product.price;
    button.append(thumb, text, price);
    return button;
  };

  const search = async () => {
    const term = query.value.trim();
    const ticket = ++lastSearch;
    if (!term) return results.replaceChildren();
    results.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(`${picker.dataset.searchUrl}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
      const products = await response.json();
      if (ticket !== lastSearch) return; // a newer search is on its way
      if (!products.length) {
        const empty = document.createElement('p');
        empty.className = 'picker-empty';
        empty.textContent = 'Nothing in the catalog matches. Add it as a new item below.';
        results.replaceChildren(empty);
      } else {
        results.replaceChildren(...products.map(resultButton));
      }
    } catch {
      results.replaceChildren();
      toast("Couldn't search the catalog. Check your connection.", true);
    } finally {
      results.removeAttribute('aria-busy');
    }
  };

  query.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(search, 250); });
  query.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); search(); } });

  $$('[data-picker]').forEach(button => button.addEventListener('click', () => {
    $$('[data-picker-form]', picker).forEach(form => form.action = button.dataset.pickerUrl);
    $('[data-picker-name]', picker).textContent = button.dataset.name;
    $('[data-picker-new-name]', picker).value = button.dataset.name;
    const skipped = button.dataset.skipped === '1';
    $('[data-picker-skip-action]', picker).value = skipped ? 'unskip' : 'skip';
    $('[data-picker-skip-label]', picker).textContent = skipped ? "Don't skip it. I need to buy this." : 'Skip it. I always have this.';
    query.value = button.dataset.name;
    results.replaceChildren();
    picker.showModal();
    search();
  }));
}

/* ---------- Slow forms: show that something is happening ---------- */

$$('form[data-busy]').forEach(form => form.addEventListener('submit', () => {
  const button = $('button', form);
  if (!button) return;
  button.disabled = true;
  button.textContent = form.dataset.busy;
}));
