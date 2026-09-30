/* Disk Forecast: the dashboard tile. */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;

  function render(root, subtitle, data) {
    var link = root.getAttribute('data-link');
    var warnings = data.targets.filter(function (t) { return t.warning || t.status === 'full'; }).length;
    var soonest = data.targets.filter(function (t) { return t.secondsToFull !== null; })
      .sort(function (a, b) { return a.secondsToFull - b.secondsToFull; })[0];

    if (subtitle) {
      subtitle.textContent = warnings
        ? warnings + (warnings === 1 ? ' target needs' : ' targets need') + ' attention'
        : (soonest ? 'Next full: ' + soonest.name + ' in ' + DF.formatDuration(soonest.secondsToFull) : 'Nothing filling up');
    }

    if (!data.targets.length) {
      root.replaceChildren(el('a', { href: link, className: 'df-faint', text: 'Nothing tracked yet. Choose what to track.' }));
      return;
    }

    root.replaceChildren.apply(root, data.targets.map(function (target) {
      return DF.targetRow(target, { tag: 'a', href: link + '?target=' + encodeURIComponent(target.id) });
    }));
  }

  function start() {
    var root = document.getElementById('df-dash');
    if (!root) {
      return;
    }
    var subtitle = document.getElementById('df-dash-sub');
    DF.getJson(root.getAttribute('data-api'), { action: 'overview' }).then(function (data) {
      render(root, subtitle, data);
    }).catch(function (error) {
      root.replaceChildren(el('span', { className: 'df-faint', text: error.message }));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
