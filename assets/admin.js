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
})();
