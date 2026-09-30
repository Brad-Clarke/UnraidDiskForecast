/* Disk Forecast: the dashboard tile. */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;

  function when(target) {
    if (!target.readings) {
      return ['Waiting for readings', ''];
    }
    switch (target.status) {
      case 'full': return ['Full', 'is-critical'];
      case 'collecting': return ['Collecting', ''];
      case 'not-filling': return ['Not filling', ''];
      default: return ['Full in ' + DF.formatDuration(target.secondsToFull), target.warning ? 'is-warning' : ''];
    }
  }

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
      var fraction = DF.usedFraction(target);
      var text = when(target);
      return el('a', { href: link, className: 'df-dash-row' }, [
        el('div', { className: 'df-dash-line' }, [
          el('strong', { text: target.name }),
          el('span', { className: 'df-dash-when df-num ' + text[1] }, [
            text[1] ? DF.icon(text[1] === 'is-critical' ? 'times-circle' : 'exclamation-triangle') : null,
            text[1] ? ' ' : null,
            text[0]
          ])
        ]),
        target.size ? el('div', { className: 'df-meter ' + text[1], 'aria-hidden': 'true' }, [el('span', { style: 'width:' + (fraction * 100).toFixed(1) + '%' })]) : null,
        target.size ? el('div', { className: 'df-faint df-num', text: DF.formatBytes(target.used) + ' of ' + DF.formatBytes(target.size) }) : null
      ]);
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
