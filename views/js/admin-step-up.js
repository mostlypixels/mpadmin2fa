(function () {
  'use strict';

  if (window.mpadmin2faStepUpListenerInstalled) {
    return;
  }

  var originalSend = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.send = function () {
    this.addEventListener('loadend', function () {
      var redirectUrl = this.getResponseHeader('X-Mpadmin2fa-Redirect');

      if (!redirectUrl) {
        return;
      }

      var target = new URL(redirectUrl, window.location.href);
      var current = new URL(window.location.href);

      // Native background requests are also gated during enrollment/challenge.
      // Keep the form in place when that response points to the form already open.
      if (target.origin === current.origin && target.pathname === current.pathname
          && target.searchParams.get('controller') === current.searchParams.get('controller')
          && (target.searchParams.get('step_up') || '0') === (current.searchParams.get('step_up') || '0')) {
        return;
      }

      window.location.assign(target.href);
    }, {once: true});

    return originalSend.apply(this, arguments);
  };

  window.mpadmin2faStepUpListenerInstalled = true;
}());
