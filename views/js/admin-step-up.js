(function () {
  'use strict';

  if (!window.mpadmin2faStepUpListenerInstalled) {
    var redirectPending = false;
    var redirectIfNeeded = function (redirectUrl) {
      if (!redirectUrl || redirectPending) {
        return;
      }

      var target;
      var current;
      try {
        target = new URL(redirectUrl, window.location.href);
        current = new URL(window.location.href);
      } catch (error) {
        return;
      }

      // Native background requests are also gated during enrollment/challenge.
      // Keep the form in place when that response points to the form already open.
      if (target.origin === current.origin && target.pathname === current.pathname
          && target.searchParams.get('controller') === current.searchParams.get('controller')
          && (target.searchParams.get('step_up') || '0') === (current.searchParams.get('step_up') || '0')) {
        return;
      }

      redirectPending = true;
      window.location.assign(target.href);
    };
    var originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function () {
      this.addEventListener('loadend', function () {
        var redirectUrl = this.getResponseHeader('X-Mpadmin2fa-Redirect');
        redirectIfNeeded(redirectUrl);
      }, {once: true});

      return originalSend.apply(this, arguments);
    };

    if (typeof window.fetch === 'function') {
      var originalFetch = window.fetch;
      window.fetch = function () {
        return originalFetch.apply(this, arguments).then(function (response) {
          redirectIfNeeded(response.headers.get('X-Mpadmin2fa-Redirect'));

          return response;
        });
      };
    }
    window.mpadmin2faStepUpListenerInstalled = true;
  }

  if (!window.mpadmin2faSecureSubmitListenerInstalled) {
    document.addEventListener('click', function (event) {
      var action = event.target;
      while (action && action !== document
        && (!action.classList || !action.classList.contains('js-mp2fa-secure-submit-row-action'))) {
        action = action.parentNode;
      }
      if (!action || action === document) {
        return;
      }
      event.preventDefault();
      var message = action.getAttribute('data-confirm-message');
      if (message && !window.confirm(message)) {
        return;
      }
      var form = document.createElement('form');
      form.method = action.getAttribute('data-method') || 'POST';
      form.action = action.getAttribute('data-url');
      form.style.display = 'none';
      var token = document.createElement('input');
      token.type = 'hidden';
      token.name = 'mp2fa_csrf_token';
      token.value = action.getAttribute('data-csrf-token') || '';
      form.appendChild(token);
      document.body.appendChild(form);
      form.submit();
    });
    window.mpadmin2faSecureSubmitListenerInstalled = true;
  }
}());
