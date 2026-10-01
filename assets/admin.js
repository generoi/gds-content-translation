(function () {
  // Survives the reload after a translation is created in another tab, and the
  // round trip of Trash / Restore / Delete permanently.
  var SEARCH_KEY = 'gdsContentTranslationSearch';

  function strings() {
    return window.contentTranslationStatus || {};
  }

  function format(template, values) {
    var index = 0;

    return String(template || '').replace(/%(\d+\$)?[sd]/g, function (match, position) {
      var value = position ? values[parseInt(position, 10) - 1] : values[index++];

      return value === undefined ? match : String(value);
    });
  }

  var liveRegion = null;

  function announce(message) {
    if (!liveRegion) {
      liveRegion = document.querySelector('[data-gds-ct-live]');
    }

    if (!liveRegion || !message) {
      return;
    }

    // Cleared first so the same message is announced again.
    liveRegion.textContent = '';
    window.setTimeout(function () {
      liveRegion.textContent = message;
    }, 50);
  }

  function saveSearch() {
    var input = document.querySelector('.gds-content-translation__search-input');

    try {
      if (input && input.value) {
        window.sessionStorage.setItem(SEARCH_KEY, input.value);
      }
    } catch (error) {
      // Private mode: carry on without the search.
    }
  }

  /* ---------------------------------------------------------------------
   * Search and summary filters
   * ------------------------------------------------------------------- */

  function normalizeSearchText(value) {
    return String(value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function parseSearchTerms(query) {
    return normalizeSearchText(query)
      .split(' ')
      .filter(Boolean);
  }

  function isSubsequenceMatch(haystack, needle) {
    var start = 0;

    for (var i = 0; i < needle.length; i++) {
      var index = haystack.indexOf(needle.charAt(i), start);

      if (index === -1) {
        return false;
      }

      start = index + 1;
    }

    return true;
  }

  function substringMatch(row, terms) {
    for (var i = 0; i < terms.length; i++) {
      if (row.text.indexOf(terms[i]) === -1) {
        return false;
      }
    }

    return true;
  }

  // Typo tolerance, only when nothing matches as typed, and only within one
  // word: "ananas" no longer finds "Ranskanperunamaustesuola".
  function fuzzyMatch(row, terms) {
    for (var i = 0; i < terms.length; i++) {
      var term = terms[i];

      if (row.text.indexOf(term) !== -1) {
        continue;
      }

      if (term.length < 3) {
        return false;
      }

      var found = false;

      for (var w = 0; w < row.words.length; w++) {
        if (row.words[w].length >= term.length && isSubsequenceMatch(row.words[w], term)) {
          found = true;
          break;
        }
      }

      if (!found) {
        return false;
      }
    }

    return true;
  }

  function initSearch() {
    var root = document.querySelector('.gds-content-translation');
    var table = root && root.querySelector('.gds-content-translation__table');

    if (!table) {
      return;
    }

    var input = root.querySelector('.gds-content-translation__search-input');
    var clearButton = root.querySelector('.gds-content-translation__search-clear');
    var status = root.querySelector('.gds-content-translation__search-status');
    var emptyRow = table.querySelector('.gds-content-translation__search-empty');
    var filterButtons = Array.prototype.slice.call(root.querySelectorAll('.gds-content-translation__filter'));
    var activeFilter = null;

    // Normalised once, not on every keystroke.
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-search-title]')).map(function (el) {
      var text = normalizeSearchText(el.getAttribute('data-search-title'));

      return {
        el: el,
        text: text,
        words: text.split(' '),
        missing: (el.getAttribute('data-missing') || '').split(' ').filter(Boolean),
        unproofread: (el.getAttribute('data-unproofread') || '').split(' ').filter(Boolean),
      };
    });
    var total = rows.length;

    function passesFilter(row) {
      if (!activeFilter) {
        return true;
      }

      return row[activeFilter.type].indexOf(activeFilter.lang) !== -1;
    }

    function formatMatchStatus(visible) {
      var search = strings().search || {};

      if (visible === total) {
        return total === 1 ? search.matchCount || '1 match' : format(search.matchCountPlural || '%d matches', [total]);
      }

      return format(search.matchCountFiltered || '%1$d of %2$d', [visible, total]);
    }

    function applyFilter() {
      var terms = input ? parseSearchTerms(input.value) : [];
      var candidates = rows.filter(passesFilter);
      var matches = terms.length ? candidates.filter(function (row) { return substringMatch(row, terms); }) : candidates;

      if (terms.length && matches.length === 0) {
        matches = candidates.filter(function (row) { return fuzzyMatch(row, terms); });
      }

      var visibleSet = new Set(matches);

      rows.forEach(function (row) {
        row.el.hidden = !visibleSet.has(row);
      });

      var narrowed = terms.length > 0 || activeFilter !== null;

      if (clearButton) {
        clearButton.hidden = !narrowed;
      }

      if (emptyRow) {
        emptyRow.hidden = matches.length > 0 || !narrowed;
      }

      if (status) {
        status.textContent = narrowed ? formatMatchStatus(matches.length) : '';
      }
    }

    filterButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        var pressed = button.getAttribute('aria-pressed') === 'true';

        filterButtons.forEach(function (other) {
          other.setAttribute('aria-pressed', 'false');
        });

        if (pressed) {
          activeFilter = null;
        } else {
          button.setAttribute('aria-pressed', 'true');
          activeFilter = { type: button.getAttribute('data-filter'), lang: button.getAttribute('data-lang') };
        }

        applyFilter();
      });
    });

    if (input) {
      input.addEventListener('input', applyFilter);
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          input.value = '';
          applyFilter();
        }
      });
    }

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        if (input) {
          input.value = '';
          input.focus();
        }

        activeFilter = null;
        filterButtons.forEach(function (button) {
          button.setAttribute('aria-pressed', 'false');
        });
        applyFilter();
      });
    }

    // Keep the row lists in step with proof-read changes made on this page.
    document.addEventListener('gds-ct-proofread', function (event) {
      var row = rows.filter(function (candidate) { return candidate.el === event.detail.row; })[0];

      if (!row) {
        return;
      }

      row.unproofread = row.unproofread.filter(function (lang) { return lang !== event.detail.lang; });

      if (!event.detail.proofread) {
        row.unproofread.push(event.detail.lang);
      }
    });

    var saved = '';

    try {
      saved = window.sessionStorage.getItem(SEARCH_KEY) || '';
      window.sessionStorage.removeItem(SEARCH_KEY);
    } catch (error) {
      saved = '';
    }

    if (saved && input) {
      input.value = saved;
      applyFilter();
    }
  }

  /* ---------------------------------------------------------------------
   * Proof-read celebration
   *
   * One canvas for the page (printed with it, never added or removed), one
   * particle pool and one animation loop that every burst joins. Emoji are
   * drawn from a sprite rendered once, so a frame never asks the document for
   * a font. Motion is time-based, so it looks the same at 60 and 120 Hz.
   * ------------------------------------------------------------------- */

  var celebrationEmojis = ['🦄', '⭐', '✨', '🌟', '💫', '🎉', '🥳', '👏', '💚', '🎊', '✅', '🌈', '🔥', '💎', '🍀'];
  var celebrationColors = ['#007017', '#72aee6', '#dba617', '#d63638', '#9b51e0', '#00a32a'];
  var PARTICLES_PER_BURST = 36;
  var MAX_PARTICLES = 150;
  var LIFETIME = 2200;
  var GRAVITY = 0.38;
  var DRAG = 0.988;
  var FRAME = 1000 / 60;

  var celebration = {
    canvas: null,
    ctx: null,
    particles: [],
    running: false,
    last: 0,
    width: 0,
    height: 0,
    dpr: 1,
    sprites: {},
  };

  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function sizeCanvas() {
    var c = celebration;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var width = window.innerWidth;
    var height = window.innerHeight;

    if (c.width === width && c.height === height && c.dpr === dpr && c.canvas.width > 0) {
      return;
    }

    c.width = width;
    c.height = height;
    c.dpr = dpr;
    c.canvas.width = Math.round(width * dpr);
    c.canvas.height = Math.round(height * dpr);
  }

  function getSprite(emoji) {
    var dpr = celebration.dpr;
    var key = emoji + '@' + dpr;

    if (celebration.sprites[key]) {
      return celebration.sprites[key];
    }

    var size = Math.ceil(32 * dpr);
    var sprite = typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(size, size) : document.createElement('canvas');

    sprite.width = size;
    sprite.height = size;

    var ctx = sprite.getContext('2d');

    ctx.font = Math.round(26 * dpr) + 'px serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(emoji, size / 2, size / 2 + dpr);

    celebration.sprites[key] = sprite;

    return sprite;
  }

  function pickCelebrationStyle() {
    if (Math.floor(Math.random() * (celebrationEmojis.length + 1)) === 0) {
      return { type: 'confetti' };
    }

    return {
      type: 'emoji',
      sprite: getSprite(celebrationEmojis[Math.floor(Math.random() * celebrationEmojis.length)]),
    };
  }

  function spawnBurst(originX, originY, now) {
    var style = pickCelebrationStyle();

    for (var i = 0; i < PARTICLES_PER_BURST; i++) {
      var angle = Math.random() * Math.PI * 2;
      var speed = 7 + Math.random() * 11;
      var particle = {
        x: originX + (Math.random() - 0.5) * 12,
        y: originY + (Math.random() - 0.5) * 8,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - (6 + Math.random() * 8),
        rotation: Math.random() * Math.PI * 2,
        spin: ((Math.random() - 0.5) * 14 * Math.PI) / 180,
        born: now,
        opacity: 1,
      };

      if (style.type === 'emoji') {
        particle.sprite = style.sprite;
        particle.size = 22 + Math.random() * 8;
      } else {
        particle.w = 8 + Math.random() * 6;
        particle.h = 12 + Math.random() * 8;
        particle.color = celebrationColors[Math.floor(Math.random() * celebrationColors.length)];
      }

      celebration.particles.push(particle);
    }

    // Rapid toggling adds bursts to the running loop, never more than this.
    if (celebration.particles.length > MAX_PARTICLES) {
      celebration.particles.splice(0, celebration.particles.length - MAX_PARTICLES);
    }
  }

  function stopCelebration() {
    var c = celebration;

    c.running = false;
    c.particles.length = 0;
    c.canvas.style.visibility = 'hidden';
    // Give the backing store back while idle.
    c.canvas.width = 0;
    c.canvas.height = 0;
    c.width = 0;
  }

  function frame(now) {
    var c = celebration;
    var dt = Math.min(now - c.last, 50) / FRAME;
    var drag = Math.pow(DRAG, dt);
    var ctx = c.ctx;
    var dpr = c.dpr;

    c.last = now;
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, c.canvas.width, c.canvas.height);

    var alive = [];

    for (var i = 0; i < c.particles.length; i++) {
      var p = c.particles[i];
      var age = now - p.born;

      p.vx *= drag;
      p.vy += GRAVITY * dt;
      p.x += p.vx * dt;
      p.y += p.vy * dt;
      p.rotation += p.spin * dt;
      p.opacity = age > LIFETIME * 0.55 ? Math.max(0, 1 - (age - LIFETIME * 0.55) / (LIFETIME * 0.45)) : 1;

      if (p.opacity <= 0 || age > LIFETIME || p.y > c.height + 60 || p.x < -40 || p.x > c.width + 40) {
        continue;
      }

      alive.push(p);

      var cos = Math.cos(p.rotation) * dpr;
      var sin = Math.sin(p.rotation) * dpr;

      ctx.globalAlpha = p.opacity;
      ctx.setTransform(cos, sin, -sin, cos, p.x * dpr, p.y * dpr);

      if (p.sprite) {
        ctx.drawImage(p.sprite, -p.size / 2, -p.size / 2, p.size, p.size);
      } else {
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
      }
    }

    ctx.globalAlpha = 1;
    c.particles = alive;

    if (alive.length) {
      window.requestAnimationFrame(frame);
    } else {
      stopCelebration();
    }
  }

  function celebrateProofread(anchor) {
    if (reducedMotion()) {
      return;
    }

    var c = celebration;

    if (!c.canvas) {
      c.canvas = document.querySelector('.gds-content-translation__celebration-canvas');
      c.ctx = c.canvas && c.canvas.getContext('2d');
    }

    if (!c.ctx) {
      return;
    }

    var rect = anchor.getBoundingClientRect();
    var now = performance.now();

    sizeCanvas();
    spawnBurst(rect.left + rect.width / 2, rect.top + rect.height / 2, now);

    if (!c.running) {
      c.running = true;
      c.last = now;
      c.canvas.style.visibility = 'visible';
      window.requestAnimationFrame(frame);
    }
  }

  window.addEventListener('resize', function () {
    if (celebration.running) {
      sizeCanvas();
    }
  });

  /* ---------------------------------------------------------------------
   * Proof-read toggle
   * ------------------------------------------------------------------- */

  function showProofreadError(target, message) {
    var label = target.closest('.gds-content-translation__proofread');
    var container = label && label.parentNode;

    if (!container) {
      return;
    }

    var error = container.querySelector('.gds-content-translation__proofread-error');

    if (!error) {
      error = document.createElement('span');
      error.className = 'gds-content-translation__proofread-error';
      container.appendChild(error);
    }

    error.textContent = message;
    announce(message);
  }

  function clearProofreadError(target) {
    var label = target.closest('.gds-content-translation__proofread');
    var error = label && label.parentNode && label.parentNode.querySelector('.gds-content-translation__proofread-error');

    if (error) {
      error.remove();
    }
  }

  document.addEventListener('change', function (event) {
    var target = event.target;

    if (!target.classList || !target.classList.contains('gds-content-translation__proofread-input')) {
      return;
    }

    var postId = target.dataset.postId;
    var settings = strings();
    var messages = settings.proofread || {};

    if (!postId || !settings.ajaxUrl) {
      return;
    }

    var checked = target.checked;

    clearProofreadError(target);
    target.classList.add('is-saving');

    var body = new FormData();
    body.append('action', 'gds_ct_save_proofread');
    body.append('nonce', settings.nonce);
    body.append('postId', postId);
    body.append('proofread', checked ? '1' : '0');

    fetch(settings.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    })
      .then(function (response) {
        // An expired nonce answers "-1" with a 403, which is not JSON.
        if (response.status === 403) {
          throw new Error('expired');
        }

        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success) {
          throw new Error((payload && payload.data && payload.data.message) || 'failed');
        }

        var cell = target.closest('td');
        var row = target.closest('tr');
        var heading = cell && row && row.closest('table').tHead.rows[0].cells[cell.cellIndex];

        document.dispatchEvent(new CustomEvent('gds-ct-proofread', {
          detail: { row: row, lang: heading ? heading.getAttribute('data-lang') : '', proofread: checked },
        }));

        announce(checked ? messages.checked : messages.unchecked);

        if (checked) {
          celebrateProofread(target);
        }
      })
      .catch(function (error) {
        target.checked = !checked;
        showProofreadError(target, error && error.message === 'expired' ? messages.expired || 'Session expired.' : messages.failed || 'Could not save.');
      })
      .finally(function () {
        target.classList.remove('is-saving');
      });
  });

  /* ---------------------------------------------------------------------
   * Trash, Restore, Delete permanently, Undo
   * ------------------------------------------------------------------- */

  document.addEventListener('click', function (event) {
    var link = event.target.closest(
      '.gds-content-translation__trash, .gds-content-translation__delete, .gds-content-translation__restore, .gds-content-translation__notice-action'
    );

    if (!link) {
      return;
    }

    // Trash and Delete permanently ask first, naming the post and language.
    var question = link.getAttribute('data-confirm');

    if (question && !window.confirm(question)) {
      event.preventDefault();
      return;
    }

    // The screen comes back to the same row; bring the search back too.
    saveSearch();
  });

  /* ---------------------------------------------------------------------
   * AI translation / Copy original
   *
   * They open the new translation in another tab. Mark the cell as in
   * progress at once, block a second click, and reload this screen when the
   * editor comes back to it, so the new translation shows without a manual
   * refresh. Only after the tab was actually left: a link opened in a
   * background tab leaves the marker until the next visit.
   * ------------------------------------------------------------------- */

  var refreshPending = false;
  var leftAfterClick = false;

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a.gds-content-translation__translate[target="_blank"]');

    if (!link) {
      return;
    }

    var cell = link.closest('.gds-content-translation__missing-cell');

    if (cell && cell.classList.contains('is-busy')) {
      event.preventDefault();
      return;
    }

    refreshPending = true;

    if (!cell) {
      return;
    }

    var settings = strings();
    var badge = cell.querySelector('.gds-content-translation__badge');
    var td = cell.closest('td');
    var row = cell.closest('tr');
    var heading = td && row && row.closest('table').tHead.rows[0].cells[td.cellIndex];

    cell.classList.add('is-busy');
    cell.querySelectorAll('.gds-content-translation__translate-actions a').forEach(function (action) {
      action.setAttribute('aria-disabled', 'true');
    });

    if (badge) {
      badge.textContent = settings.creating || 'Creating…';
      badge.classList.add('is-creating');
    }

    announce(format(settings.creatingAnnounce || '', [
      heading ? heading.textContent.trim() : '',
      row ? row.getAttribute('data-search-title') : '',
    ]));
  });

  document.addEventListener('visibilitychange', function () {
    if (!refreshPending) {
      return;
    }

    if (document.visibilityState === 'hidden') {
      leftAfterClick = true;
      return;
    }

    if (!leftAfterClick) {
      return;
    }

    saveSearch();
    window.location.reload();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSearch);
  } else {
    initSearch();
  }
})();
