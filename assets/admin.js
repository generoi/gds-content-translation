(function () {
  function normalizeSearchText(value) {
    return String(value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function parseSearchTerms(query) {
    return normalizeSearchText(query)
      .split(' ')
      .filter(Boolean);
  }

  function rowMatchesSearch(title, terms) {
    if (!terms.length) {
      return true;
    }

    var haystack = normalizeSearchText(title);

    if (!haystack) {
      return false;
    }

    for (var i = 0; i < terms.length; i++) {
      var term = terms[i];

      if (haystack.indexOf(term) !== -1) {
        continue;
      }

      if (isSubsequenceMatch(haystack, term)) {
        continue;
      }

      return false;
    }

    return true;
  }

  function isSubsequenceMatch(haystack, needle) {
    if (needle.length < 3) {
      return false;
    }

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

  function initSearch() {
    var root = document.querySelector('.gds-content-translation');

    if (!root) {
      return;
    }

    var input = root.querySelector('.gds-content-translation__search-input');
    var clearButton = root.querySelector('.gds-content-translation__search-clear');
    var status = root.querySelector('.gds-content-translation__search-status');
    var table = root.querySelector('.gds-content-translation__table');

    if (!input || !table) {
      return;
    }

    var rows = Array.prototype.slice.call(
      table.querySelectorAll('tbody tr[data-search-title]')
    );
    var total = rows.length;
    var emptyRow = table.querySelector('.gds-content-translation__search-empty');

    function formatMatchStatus(visible, total) {
      var strings = window.contentTranslationStatus && window.contentTranslationStatus.search;

      if (!strings) {
        if (visible === 0) {
          return 'No matches';
        }

        if (visible === total) {
          return total + ' matches';
        }

        return visible + ' of ' + total;
      }

      if (visible === 0) {
        return strings.noMatches;
      }

      if (visible === total) {
        if (total === 1) {
          return strings.matchCount;
        }

        return strings.matchCountPlural.replace('%d', String(total));
      }

      return strings.matchCountFiltered
        .replace('%1$d', String(visible))
        .replace('%2$d', String(total));
    }

    function updateStatus(visible) {
      if (!status) {
        return;
      }

      if (!input.value.trim()) {
        status.textContent = '';
        return;
      }

      status.textContent = formatMatchStatus(visible, total);
    }

    function applyFilter() {
      var terms = parseSearchTerms(input.value);
      var visible = 0;

      rows.forEach(function (row) {
        var matches = rowMatchesSearch(row.dataset.searchTitle || '', terms);

        row.hidden = !matches;

        if (matches) {
          visible += 1;
        }
      });

      if (clearButton) {
        clearButton.hidden = terms.length === 0;
      }

      if (emptyRow) {
        emptyRow.hidden = visible > 0 || terms.length === 0;
      }

      updateStatus(visible);
    }

    input.addEventListener('input', applyFilter);

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        input.value = '';
        input.focus();
        applyFilter();
      });
    }

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        input.value = '';
        applyFilter();
      }
    });
  }

  var celebrationEmojis = ['🦄', '⭐', '✨', '🌟', '💫', '🎉', '🥳', '👏', '💚', '🎊', '✅', '🌈', '🔥', '💎', '🍀'];
  var celebrationColors = ['#007017', '#72aee6', '#dba617', '#d63638', '#9b51e0', '#00a32a'];

  function pickCelebrationStyle() {
    var optionCount = celebrationEmojis.length + 1;

    if (Math.floor(Math.random() * optionCount) === 0) {
      return { type: 'confetti' };
    }

    return {
      type: 'emoji',
      value: celebrationEmojis[Math.floor(Math.random() * celebrationEmojis.length)],
    };
  }

  function createParticles(originX, originY, style) {
    var particles = [];
    var count = 36;

    for (var i = 0; i < count; i++) {
      var angle = Math.random() * Math.PI * 2;
      var speed = 7 + Math.random() * 11;
      var particle = {
        x: originX + (Math.random() - 0.5) * 12,
        y: originY + (Math.random() - 0.5) * 8,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - (6 + Math.random() * 8),
        rotation: Math.random() * 360,
        spin: (Math.random() - 0.5) * 14,
        opacity: 1,
      };

      if (style.type === 'emoji') {
        particle.emoji = style.value;
        particle.size = 22 + Math.random() * 8;
      } else {
        particle.w = 8 + Math.random() * 6;
        particle.h = 12 + Math.random() * 8;
        particle.color = celebrationColors[Math.floor(Math.random() * celebrationColors.length)];
      }

      particles.push(particle);
    }

    return particles;
  }

  function drawParticle(ctx, particle, style) {
    if (particle.opacity <= 0) {
      return;
    }

    ctx.save();
    ctx.globalAlpha = particle.opacity;
    ctx.translate(particle.x, particle.y);
    ctx.rotate((particle.rotation * Math.PI) / 180);

    if (style.type === 'emoji') {
      ctx.font = particle.size + 'px serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(particle.emoji, 0, 0);
    } else {
      ctx.fillStyle = particle.color;
      ctx.fillRect(-particle.w / 2, -particle.h / 2, particle.w, particle.h);
    }

    ctx.restore();
  }

  function celebrateProofread(anchor) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    var rect = anchor.getBoundingClientRect();
    var originX = rect.left + rect.width / 2;
    var originY = rect.top + rect.height / 2;
    var style = pickCelebrationStyle();
    var particles = createParticles(originX, originY, style);

    var canvas = document.createElement('canvas');
    canvas.className = 'gds-content-translation__celebration-canvas';
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    document.body.appendChild(canvas);

    var ctx = canvas.getContext('2d');
    var start = performance.now();
    var duration = 2200;
    var gravity = 0.38;
    var drag = 0.988;

    function frame(now) {
      var elapsed = now - start;
      var alive = false;

      ctx.clearRect(0, 0, canvas.width, canvas.height);

      for (var i = 0; i < particles.length; i++) {
        var particle = particles[i];

        particle.vx *= drag;
        particle.vy += gravity;
        particle.x += particle.vx;
        particle.y += particle.vy;
        particle.rotation += particle.spin;

        if (elapsed > duration * 0.55) {
          particle.opacity = Math.max(
            0,
            1 - (elapsed - duration * 0.55) / (duration * 0.45)
          );
        }

        if (
          particle.opacity > 0 &&
          particle.y > -40 &&
          particle.y < canvas.height + 60 &&
          particle.x > -40 &&
          particle.x < canvas.width + 40
        ) {
          alive = true;
          drawParticle(ctx, particle, style);
        }
      }

      if (alive && elapsed < duration) {
        requestAnimationFrame(frame);
      } else {
        canvas.remove();
      }
    }

    requestAnimationFrame(frame);
  }

  document.addEventListener('change', function (event) {
    var target = event.target;

    if (!target.classList.contains('gds-content-translation__proofread-input')) {
      return;
    }

    var postId = target.dataset.postId;

    if (!postId || !window.contentTranslationStatus) {
      return;
    }

    target.classList.add('is-saving');

    var body = new FormData();
    body.append('action', 'gds_ct_save_proofread');
    body.append('nonce', contentTranslationStatus.nonce);
    body.append('postId', postId);
    body.append('proofread', target.checked ? '1' : '0');

    fetch(contentTranslationStatus.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload.success) {
          target.checked = !target.checked;
          return;
        }

        if (target.checked) {
          celebrateProofread(target);
        }
      })
      .catch(function () {
        target.checked = !target.checked;
      })
      .finally(function () {
        target.classList.remove('is-saving');
      });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSearch);
  } else {
    initSearch();
  }
})();
