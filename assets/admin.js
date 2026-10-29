/**
 * The server-rendered screens' script: the AI review page and the settings.
 * The status list itself is the DataViews app in build/ (resources/).
 */
(function () {
  function strings() {
    return window.contentTranslationStatus || {};
  }

  /* ---------------------------------------------------------------------
   * Proof-read checkbox (AI review page), saved at once
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
      error.setAttribute('role', 'alert');
      container.appendChild(error);
    }

    error.textContent = message;
  }

  document.addEventListener('change', function (event) {
    var target = event.target;

    if (!target.classList || !target.classList.contains('gds-content-translation__proofread-input')) {
      return;
    }

    var settings = strings();
    var messages = settings.proofread || {};
    var postId = target.dataset.postId;

    if (!postId || !settings.ajaxUrl) {
      return;
    }

    var checked = target.checked;
    var previous = target.parentNode.parentNode.querySelector('.gds-content-translation__proofread-error');

    if (previous) {
      previous.remove();
    }

    target.classList.add('is-saving');

    var body = new FormData();
    body.append('action', 'gds_ct_save_proofread');
    body.append('nonce', settings.nonce);
    body.append('postId', postId);
    body.append('proofread', checked ? '1' : '0');

    fetch(settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) {
        // An expired nonce answers "-1" with a 403, which is not JSON.
        if (response.status === 403) {
          throw new Error('expired');
        }

        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success) {
          throw new Error('failed');
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
   * "Run the review again" asks first when it would replace open remarks
   * ------------------------------------------------------------------- */

  document.addEventListener('click', function (event) {
    var link = event.target.closest('[data-confirm]');

    if (link && !window.confirm(link.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  /* ---------------------------------------------------------------------
   * Focus the latest notice, so it is read out and its action is one Tab away
   *
   * After load: WordPress moves notices under the heading on DOM ready, and
   * moving a focused element drops its focus.
   * ------------------------------------------------------------------- */

  function focusNotice() {
    var notices = document.querySelectorAll('[data-gds-ct-notice]');

    if (notices.length) {
      notices[notices.length - 1].focus();
    }
  }

  if (document.readyState === 'complete') {
    focusNotice();
  } else {
    window.addEventListener('load', focusNotice);
  }

  /* ---------------------------------------------------------------------
   * AI review in progress
   *
   * A review page waiting on its AI review asks every few seconds whether it
   * is done, and reloads once it is. Gives up after a quarter of an hour.
   * ------------------------------------------------------------------- */

  (function () {
    var page = document.querySelector('[data-gds-ct-review][data-status="pending"]');
    var settings = strings();

    if (!page || !settings.ajaxUrl || !window.fetch) {
      return;
    }

    var id = page.getAttribute('data-gds-ct-review');
    var started = Date.now();

    function check() {
      if (Date.now() - started > 15 * 60 * 1000) {
        return;
      }

      var url = settings.ajaxUrl + '?action=gds_ct_audit_status&nonce=' + encodeURIComponent(settings.nonce) + '&posts=' + encodeURIComponent(id);

      window.fetch(url, { credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          var status = result && result.data ? result.data[id] : '';

          if (status && status !== 'pending') {
            window.location.reload();
            return;
          }

          window.setTimeout(check, 4000);
        })
        .catch(function () {
          window.setTimeout(check, 8000);
        });
    }

    window.setTimeout(check, 4000);
  })();
  /* ---------------------------------------------------------------------
   * AI review page: "Translate into …" for pages a link still leads to
   *
   * Queues the AI translation in the background (the status screen's
   * translate action) and follows it in its row: Translating…, then done
   * with links to the new page, or the error. The page stays where it is.
   * ------------------------------------------------------------------- */

  (function () {
    var settings = strings();
    var texts = settings.linkTranslate || {};
    var apiFetch = window.wp && window.wp.apiFetch;

    if (!apiFetch || !settings.restNamespace || !document.querySelector('.gds-content-translation-review__link-translate')) {
      return;
    }

    var base = '/' + settings.restNamespace;

    // "%s" in a translated string, filled in.
    function format(template, values) {
      var index = 0;

      return String(template || '').replace(/%(\d+\$)?s/g, function (match, position) {
        var value = position ? values[parseInt(position, 10) - 1] : values[index++];
        return value === undefined ? '' : String(value);
      });
    }

    function setStatus(row, html, state) {
      var status = row.querySelector('.gds-content-translation-review__link-status');
      row.setAttribute('data-state', state);
      status.innerHTML = html;
    }

    function escapeHtml(text) {
      var div = document.createElement('div');
      div.textContent = String(text || '');
      return div.innerHTML;
    }

    function follow(row, sourceId, lang, languageName) {
      var started = Date.now();

      function poll() {
        apiFetch({ path: base + '/jobs' }).then(function (result) {
          var job = (result.jobs || []).filter(function (candidate) {
            return candidate.sourceId === sourceId && candidate.language === lang;
          })[0];

          if (!job || job.status === 'queued' || job.status === 'running') {
            setStatus(row, '<span class="spinner is-active"></span>' + escapeHtml(job && job.status === 'queued' ? texts.queued : texts.translating), 'working');

            if (Date.now() - started < 10 * 60 * 1000) {
              window.setTimeout(poll, 3000);
            }

            return;
          }

          if (job.status === 'failed') {
            setStatus(row, escapeHtml(format(texts.failed, [job.error])), 'failed');
            row.querySelector('button').disabled = false;
            return;
          }

          var links = [];

          if (job.editUrl) {
            links.push('<a href="' + escapeHtml(job.editUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(texts.edit) + '</a>');
          }

          if (job.reviewUrl) {
            links.push('<a href="' + escapeHtml(job.reviewUrl) + '">' + escapeHtml(texts.review) + '</a>');
          }

          setStatus(row, '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' + escapeHtml(format(texts.done, [languageName])) + (links.length ? ' ' + links.join(' · ') : ''), 'done');
          row.querySelector('button').hidden = true;
        }).catch(function () {
          window.setTimeout(poll, 6000);
        });
      }

      poll();
    }

    document.addEventListener('click', function (event) {
      var button = event.target.closest('.gds-content-translation-review__link-translate');

      if (!button || button.disabled) {
        return;
      }

      var row = button.closest('[data-gds-ct-link-row]');
      var sourceId = parseInt(button.getAttribute('data-source-id'), 10);
      var lang = button.getAttribute('data-lang');
      var languageName = button.getAttribute('data-language-name');

      button.disabled = true;
      setStatus(row, '<span class="spinner is-active"></span>' + escapeHtml(texts.translating), 'working');

      apiFetch({
        path: base + '/actions/translate',
        method: 'POST',
        data: { ids: [sourceId], args: { languages: [lang] } }
      }).then(function (response) {
        var result = (response.results || [])[0];

        if (!response.ok && result && !result.ok) {
          setStatus(row, escapeHtml(format(texts.failed, [result.message || response.message])), 'failed');
          button.disabled = false;
          return;
        }

        follow(row, sourceId, lang, languageName);
      }).catch(function (error) {
        setStatus(row, escapeHtml(format(texts.failed, [(error && error.message) || ''])), 'failed');
        button.disabled = false;
      });
    });
  })();

})();
