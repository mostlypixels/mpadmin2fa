(function () {
  'use strict';

  if (window.mpadmin2faStepUpListenerInstalled) {
    return;
  }

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
}());
