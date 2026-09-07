(function () {
  'use strict';

  if (!window.mpadmin2faStepUpListenerInstalled) {
    var originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.send = function () {
      this.addEventListener('loadend', function () {
        var redirectUrl = this.getResponseHeader('X-Mpadmin2fa-Redirect');

        if (redirectUrl) {
          window.location.assign(redirectUrl);
        }
      }, {once: true});

      return originalSend.apply(this, arguments);
    };

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

      var confirmationMessage = action.getAttribute('data-confirm-message');
      if (confirmationMessage && !window.confirm(confirmationMessage)) {
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
