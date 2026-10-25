// Home Pantry — progressive enhancements. Every form also works without JS.

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const peso = value => '₱' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/* ---------- SweetAlert2 for confirmations, toasts and error alerts ---------- */

// In the app's own look. Without the library everything falls back to the plain
// markup and the browser's confirm(), so nothing depends on it.
const swal = window.Swal?.mixin({
  buttonsStyling: false,
  reverseButtons: true,
  heightAuto: false,
  customClass: {
    popup: 'swal-pantry',
    actions: 'swal-pantry-actions',
    confirmButton: 'button primary',
    denyButton: 'button secondary danger',
    cancelButton: 'button secondary',
  },
});
// Inside an open <dialog>, or a modal dialog's top layer would cover it.
const swalTarget = () => document.querySelector('dialog[open]') || 'body';

/* ---------- Navigation drawer ---------- */

let menuOpener = null;
function setMenu(open, opener) {
  if (open === document.body.classList.contains('nav-open')) return;
  document.body.classList.toggle('nav-open', open);
  $$('[data-menu]').forEach(button => button.setAttribute('aria-expanded', String(open)));
  // While open, the page behind is out of reach; focus moves into the drawer
  // and returns to whichever button opened it.
  $$('.mobile-header, .workspace, .tab-bar').forEach(el => el.inert = open);
  if (open) {
    menuOpener = opener;
    $('#sidebar [data-close-menu]')?.focus();
  } else {
    menuOpener?.focus();
  }
}
$$('[data-menu]').forEach(button => button.addEventListener('click', () => setMenu(!document.body.classList.contains('nav-open'), button)));
$$('[data-close-menu]').forEach(el => el.addEventListener('click', () => setMenu(false)));
document.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });

/* ---------- Full-bleed hero: the phone header is see-through only at the top ---------- */

const mobileHeader = $('.mobile-header');
if ($('[data-hero]') && mobileHeader) {
  const paintHeader = () => mobileHeader.classList.toggle('is-solid', scrollY > 40);
  addEventListener('scroll', paintHeader, { passive: true });
  paintHeader();
}

/* ---------- Lazy photos: fade in once they arrive ---------- */

$$('img[loading="lazy"]').forEach(img => {
  if (img.complete) return;
  img.classList.add('lazy-fade', 'is-loading');
  const shown = () => img.classList.remove('is-loading');
  img.addEventListener('load', shown, { once: true });
  img.addEventListener('error', shown, { once: true });
});

/* ---------- Group chips: bring the selected one into view ---------- */

const chip = $('.chip-row .chip.selected');
if (chip) chip.parentElement.scrollLeft = chip.offsetLeft - chip.parentElement.clientWidth / 2 + chip.clientWidth / 2;

/* ---------- Dialogs and confirmations ---------- */

// A [data-dialog] link opens the dialog instead of its fallback page.
$$('[data-dialog]').forEach(button => button.addEventListener('click', event => {
  const dialog = document.getElementById(button.dataset.dialog);
  if (!dialog) return;
  event.preventDefault();
  dialog.showModal();
}));
$$('dialog[data-open]').forEach(dialog => dialog.showModal());
$$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
$$('dialog').forEach(dialog => dialog.addEventListener('click', event => {
  const box = dialog.getBoundingClientRect();
  const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
  if (outside && event.target === dialog) dialog.close();
}));
// "Delete X? This also…" asks first. The question is the title, the rest the detail,
// and the confirm button repeats the form's own button ("Delete product").
$$('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (form.dataset.confirmed) return void delete form.dataset.confirmed;
  event.preventDefault();
  event.stopImmediatePropagation();
  const submitter = event.submitter;
  const go = () => {
    form.dataset.confirmed = '1';
    submitter ? form.requestSubmit(submitter) : form.requestSubmit();
  };
  // data-confirm-type="RESET": the word must be typed, and goes to the server as "confirmation".
  const word = form.dataset.confirmType;
  const typed = () => { if (word) form.elements.confirmation.value = word; go(); };
  if (!swal) {
    if (word) return void (prompt(`${form.dataset.confirm}\n\nType ${word} to confirm.`)?.trim() === word && typed());
    return void (confirm(form.dataset.confirm) && go());
  }
  const [title, ...detail] = form.dataset.confirm.split(/(?<=\?)\s+/);
  swal.fire({
    target: swalTarget(),
    icon: 'warning',
    title,
    text: detail.join(' '),
    showCancelButton: true,
    confirmButtonText: form.dataset.confirmButton || submitter?.textContent.trim() || 'Continue',
    // data-confirm-deny adds a second, bigger choice that sets a hidden field ("…and their recipes").
    showDenyButton: Boolean(form.dataset.confirmDeny),
    denyButtonText: form.dataset.confirmDeny,
    cancelButtonText: 'Cancel',
    focusCancel: !word,
    ...(word && {
      input: 'text',
      inputLabel: `Type ${word} to confirm`,
      inputAttributes: { autocapitalize: 'characters', autocomplete: 'off', spellcheck: 'false' },
      inputValidator: value => value.trim() === word ? null : `Type ${word} exactly to go ahead.`,
    }),
  }).then(result => {
    if (form.dataset.confirmDenyField) form.elements[form.dataset.confirmDenyField].value = result.isDenied ? '1' : '0';
    if (result.isConfirmed && word) return typed();
    if (result.isConfirmed || result.isDenied) go();
  });
}));

/* ---------- Toasts ---------- */

// toast(message), toast(message, true) for an error, or toast(message, 'info') for a heads-up.
function toast(message, kind = false) {
  if (!message) return;
  const isError = kind === true;
  // A toast would replace a SweetAlert2 dialog that is open (not one already closing), so then the plain toast is used.
  const popup = window.Swal?.isVisible() && window.Swal.getPopup();
  const dialogOpen = popup && !popup.classList.contains('swal2-toast') && !popup.classList.contains('swal2-hide');
  if (swal && !dialogOpen) {
    swal.fire({
      target: swalTarget(),
      toast: true,
      position: matchMedia('(max-width: 760px)').matches ? 'top' : 'bottom-end',
      icon: isError ? 'error' : kind === 'info' ? 'info' : 'success',
      title: message,
      showConfirmButton: false,
      timer: isError ? 5000 : 3200,
      timerProgressBar: true,
      customClass: { popup: 'swal-pantry-toast' },
      didOpen: popup => {
        popup.addEventListener('mouseenter', window.Swal.stopTimer);
        popup.addEventListener('mouseleave', window.Swal.resumeTimer);
      },
    });
    return;
  }
  const region = $('.toast-region');
  if (!region) return;
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
// Messages the server flashed (saved, deleted…) become SweetAlert2 toasts too.
$$('.toast-region .toast').forEach(el => {
  if (!swal) return scheduleDismiss(el);
  toast(el.textContent.trim(), el.classList.contains('error'));
  el.remove();
});

// Form errors, unless an editor dialog reopened to show them in place.
const errorNotice = $('[data-swal-errors]');
if (errorNotice && swal && !$('dialog[data-open]')) {
  const messages = $$('p', errorNotice).map(p => p.textContent);
  errorNotice.remove();
  swal.fire({
    icon: 'error',
    title: messages.length > 1 ? 'A few things to fix' : 'One thing to fix',
    html: messages.map(message => `<p>${message.replace(/[&<>"]/g, c => `&#${c.charCodeAt(0)};`)}</p>`).join(''),
    confirmButtonText: 'OK',
  });
}

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
  // Ticking an item is instant; offline, the tick waits on this device (see "Offline ticks").
  'cart-check': {
    offline: true,
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
  if (checkout) checkout.disabled = checked === 0 || !navigator.onLine;
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
    if (handler.offline) dropFromOutbox(form.action);
    handler.done?.(form, data);
    setCartCount(data.cartCount);
    if (!handler.optimistic && !handler.quiet) toast(data.message);
  } catch (error) {
    // fetch() rejects with a TypeError when the request never reached the server.
    if (handler.offline && (error instanceof TypeError || !navigator.onLine)) return queueOffline(form, body);
    revert?.();
    toast(navigator.onLine ? error.message : "You're offline. That change wasn't saved.", true);
  }
});

/* ---------- Offline ticks: saved on this device, sent when the connection is back ---------- */

const outboxKey = 'pantry:outbox';
const readOutbox = () => { try { return JSON.parse(localStorage.getItem(outboxKey) || '{}'); } catch { return {}; } };
const writeOutbox = box => { try { localStorage.setItem(outboxKey, JSON.stringify(box)); } catch {} };
let warnedOffline = false;

// Only an item's latest tick matters, so the outbox maps each item's URL to the state to send.
function queueOffline(form, body) {
  writeOutbox({ ...readOutbox(), [form.action]: body.get('checked') });
  paintOutbox();
  if (!warnedOffline) toast("You're offline. Ticks are saved on this phone and sync when you're back online.", 'info');
  warnedOffline = true;
}

function dropFromOutbox(url) {
  const box = readOutbox();
  if (!(url in box)) return;
  delete box[url];
  writeOutbox(box);
  paintOutbox();
}

// Marks rows still waiting to sync, and replays their ticks on a page that came from the offline copy.
function paintOutbox() {
  const box = readOutbox();
  $$('form[data-async="cart-check"]').forEach(form => {
    const row = form.closest('[data-row]');
    const waiting = form.action in box;
    row.classList.toggle('is-pending', waiting);
    if (!waiting) return;
    const checked = box[form.action] === '1';
    row.classList.toggle('is-checked', checked);
    $('button', form).setAttribute('aria-pressed', String(checked));
    $('input[name=checked]', form).value = checked ? '0' : '1';
  });
  const waiting = Object.keys(box).length;
  $$('[data-sync-note]').forEach(note => {
    note.hidden = !waiting && navigator.onLine;
    note.textContent = waiting
      ? `${waiting} ${waiting === 1 ? 'tick' : 'ticks'} waiting to sync${navigator.onLine ? '…' : '. They go through when you\'re back online.'}`
      : "You're offline. Ticks are saved on this phone until you're back.";
  });
  if ($('[data-row]')) refreshSummary();
}

async function flushOutbox() {
  const box = readOutbox();
  const urls = Object.keys(box);
  if (!urls.length || !navigator.onLine) return paintOutbox();
  let synced = 0;
  for (const url of urls) {
    const body = new FormData();
    body.set('_token', $('meta[name=csrf-token]').content);
    body.set('_method', 'PATCH');
    body.set('checked', box[url]);
    try {
      const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      if (response.status === 419 || response.status === 401) {
        toast('Sign in again to sync your ticks.', true);
        break;
      }
      // Anything else is settled: saved, or the item was removed from the list meanwhile.
      const current = readOutbox();
      if (current[url] === box[url]) delete current[url];
      writeOutbox(current);
      if (response.ok) setCartCount((await response.json().catch(() => ({}))).cartCount);
      synced++;
    } catch {
      break; // still offline
    }
  }
  paintOutbox();
  if (synced) toast(`Back online. ${synced} ${synced === 1 ? 'tick' : 'ticks'} synced.`);
}

addEventListener('online', flushOutbox);
addEventListener('offline', paintOutbox);
flushOutbox();

// The offline copy of the shopping list (sw.js), for opening it in a store with no signal.
if ('serviceWorker' in navigator && window.isSecureContext) {
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}
// Signing out clears this device's copy and any ticks still waiting.
$$('form[data-logout]').forEach(form => form.addEventListener('submit', () => {
  try { localStorage.removeItem(outboxKey); } catch {}
  if ('caches' in window) caches.delete('pantry-offline');
}));

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
  const number = value => Number(value).toLocaleString('en-US');
  const runForm = $('[data-run-queue]', statusPanel);
  const runButton = $('[data-run-button]', statusPanel);
  const rate = $('[data-queue-rate]', statusPanel);
  const eta = $('[data-queue-eta]', statusPanel);
  const note = $('[data-queue-note]', statusPanel);
  const samples = []; // [time, photos downloaded], for the speed
  let running = false;
  let polling = false;

  const paint = data => {
    for (const [key, value] of Object.entries(data)) {
      $$(`[data-status="${key}"]`, statusPanel).forEach(el => el.textContent = number(value));
    }
    const percent = data.withPhotoUrl ? Math.floor(100 * data.localImages / data.withPhotoUrl) : 100;
    $('[data-queue-bar]', statusPanel).style.width = percent + '%';
    $('[data-queue-bar]', statusPanel).parentElement.setAttribute('aria-valuenow', percent);
    $('[data-queue-percent]', statusPanel).textContent = percent + '%';

    samples.push([Date.now(), data.localImages]);
    while (samples.length > 2 && samples[0][0] < Date.now() - 120000) samples.shift();
    const [[firstAt, firstDone], [lastAt, lastDone]] = [samples[0], samples[samples.length - 1]];
    const perMinute = lastAt > firstAt ? (lastDone - firstDone) / ((lastAt - firstAt) / 60000) : 0;
    rate.hidden = eta.hidden = !(perMinute > 0 && data.queued > 0);
    if (!rate.hidden) {
      const minutes = data.queued / perMinute;
      rate.textContent = `About ${number(Math.round(perMinute))} a minute`;
      eta.textContent = minutes < 1 ? 'Less than a minute left' : minutes < 90 ? `About ${Math.round(minutes)} min left` : `About ${Math.round(minutes / 60)} hr left`;
    }

    note.textContent = running ? `Downloading here… ${number(data.queued)} to go. Keep this page open, or tap Stop.`
      : data.queued ? `${number(data.queued)} queued. The background worker downloads them, or tap Download now.`
      : data.localImages < data.withPhotoUrl ? `${number(data.withPhotoUrl - data.localImages)} photos aren't downloaded or queued. Queue them, then download.`
      : 'Every product photo is on this server.';
    runButton.disabled = !running && !data.queued;
  };

  const status = async () => (await fetch(statusPanel.dataset.photoStatus, { headers: { Accept: 'application/json' } })).json();
  const poll = async () => {
    if (polling) return;
    polling = true;
    try {
      const data = await status();
      paint(data);
      polling = false;
      if (data.queued > 0 || running) setTimeout(poll, 5000);
    } catch { polling = false; setTimeout(poll, 15000); }
  };

  // "Download now": each request downloads for about 20 seconds; keep asking until the queue is empty or Stop.
  const setRunning = on => {
    running = on;
    $('span', runButton).textContent = on ? 'Stop' : 'Download now';
    runButton.classList.toggle('secondary', on);
    runButton.classList.toggle('primary', !on);
  };
  let loop = null;
  runForm.addEventListener('submit', event => {
    event.preventDefault();
    setRunning(!running);
    // Stop lets the batch in flight finish; tapping again before then just keeps that loop going.
    if (running && !loop) {
      loop = runQueue().finally(() => {
        loop = null;
        setRunning(false);
        status().then(paint).catch(() => {});
      });
    }
  });
  const runQueue = async () => {
    poll();
    while (running) {
      try {
        const response = await fetch(statusPanel.dataset.runUrl, {
          method: 'POST',
          body: new FormData(runForm),
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        if (response.status === 419 || response.status === 401) return location.reload();
        if (!response.ok) throw new Error();
        const data = await response.json();
        paint(data);
        if (!data.queued) break;
      } catch {
        toast(navigator.onLine ? 'Downloading stopped after an error. Tap Download now to try again.' : "You're offline. Downloading stopped.", true);
        break;
      }
    }
  };

  if (Number($('[data-status="queued"]', statusPanel).textContent.replace(/,/g, '')) > 0) poll();
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

/* ---------- Recipe cards: rise into view, a few at a time ---------- */

const recipeCards = $$('.recipe-card');
if (recipeCards.length && 'IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const reveal = new IntersectionObserver(entries => {
    const arriving = entries.filter(entry => entry.isIntersecting);
    arriving.forEach((entry, index) => {
      entry.target.style.animationDelay = Math.min(index * 80, 400) + 'ms';
      entry.target.classList.add('is-revealed');
      reveal.unobserve(entry.target);
    });
  }, { rootMargin: '0px 0px -40px 0px' });
  recipeCards.forEach(card => { card.classList.add('will-reveal'); reveal.observe(card); });
}

/* ---------- Chef page photo: preview an upload before it is saved ---------- */

$$('[data-hero-picker]').forEach(picker => {
  const upload = $('[data-hero-upload]', picker);
  const tile = $('[data-hero-photo]', picker);
  const preview = $('img', tile);
  const restore = () => {
    if (preview.dataset.saved) preview.src = preview.dataset.saved;
    else preview.removeAttribute('src');
    tile.hidden = !preview.dataset.saved;
  };
  upload.addEventListener('change', () => {
    const file = upload.files[0];
    if (!file) return restore();
    preview.src = URL.createObjectURL(file);
    tile.hidden = false;
    $('input[type=radio]', tile).checked = true;
  });
  // Picking another photo drops a file chosen but not yet saved.
  picker.addEventListener('change', event => {
    if (event.target.type === 'radio' && event.target.value !== 'photo' && upload.value) {
      upload.value = '';
      restore();
    }
  });
});

/* ---------- Recipe ingredient picker ---------- */

const picker = $('#ingredient-picker');
if (picker) {
  const query = $('[data-picker-query]', picker);
  const results = $('[data-picker-results]', picker);
  let searchTimer;
  let lastSearch = 0;

  const resultButton = product => {
    const button = document.createElement('button');
    button.className = 'picker-result' + (product.pantry ? ' in-pantry' : '');
    button.name = 'product_id';
    button.value = product.id;
    const thumb = document.createElement('span');
    thumb.className = 'thumb';
    if (product.image) {
      const img = document.createElement('img');
      img.src = product.image;
      img.alt = '';
      img.loading = 'lazy';
      img.decoding = 'async';
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
    price.textContent = !product.pantry ? product.price : product.atHome ? `At home: ${product.atHome}` : 'None at home';
    button.append(thumb, text, price);
    return button;
  };
  const groupLabel = (label, pantry = false) => {
    const el = document.createElement('p');
    el.className = 'option-group' + (pantry ? ' pantry' : '');
    el.textContent = label;
    return el;
  };

  const search = async () => {
    // An empty box lists the whole pantry, to pick from without typing.
    const term = query.value.trim();
    const ticket = ++lastSearch;
    results.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(`${picker.dataset.searchUrl}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
      const products = await response.json();
      if (ticket !== lastSearch) return; // a newer search is on its way
      if (!products.length) {
        const empty = document.createElement('p');
        empty.className = 'picker-empty';
        empty.textContent = term ? 'Nothing in the catalog matches. Add it as a new item below.' : 'Your pantry is empty. Type to search the catalog.';
        results.replaceChildren(empty);
      } else {
        // What is already in the pantry comes first, under its own heading.
        const mine = products.filter(product => product.pantry);
        const rest = products.filter(product => !product.pantry);
        results.replaceChildren(
          ...(mine.length ? [groupLabel('In your pantry', true), ...mine.map(resultButton)] : []),
          ...(mine.length && rest.length ? [groupLabel('From the catalog')] : []),
          ...rest.map(resultButton),
        );
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
    const newName = $('[data-picker-new-name]', picker);
    if (newName) newName.value = button.dataset.name;
    const newLink = $('[data-picker-new-link]', picker);
    if (newLink) newLink.href = button.dataset.newItemUrl;
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
