/* Disk Forecast: the dashboard tile. */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;

  function render(root, subtitle, data) {
    var link = root.getAttribute('data-link');
    var shown = data.targets.filter(function (t) { return t.onDashboard; });
    var warnings = shown.filter(function (t) { return t.warning || t.status === 'full'; }).length;
    var soonest = shown.filter(function (t) { return t.secondsToFull !== null; })
      .sort(function (a, b) { return a.secondsToFull - b.secondsToFull; })[0];
    var collecting = shown.filter(function (t) { return t.readings && t.status === 'collecting'; })
      .sort(function (a, b) { return a.forecastFrom - b.forecastFrom; })[0];

    if (subtitle) {
      subtitle.textContent = warnings
        ? warnings + (warnings === 1 ? ' target needs' : ' targets need') + ' attention'
        : (soonest ? 'Next full: ' + soonest.name + ' in ' + DF.formatDuration(soonest.secondsToFull)
          : (collecting ? DF.rowStatus(collecting).text : 'Nothing filling up'));
    }

    root.replaceChildren.apply(root, shown.map(function (target) {
      return DF.targetRow(target, { tag: 'a', href: link + '?target=' + encodeURIComponent(target.id) });
    }));
  }

  function start() {
    var root = document.getElementById('df-dash');
    if (!root) {
      return;
    }
    DF.configure(root);
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
