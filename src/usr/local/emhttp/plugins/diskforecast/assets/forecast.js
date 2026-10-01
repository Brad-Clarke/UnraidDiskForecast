/* Disk Forecast: the forecast page (target list, graph, projection table, what-if drive). */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;
  var SPANS = [[30, '30D'], [90, '90D'], [365, '1Y'], [0, 'All']];
  var DRIVE_SIZES = [8, 12, 16, 20, 24, 28];

  function ForecastView(root) {
    DF.configure(root);
    this.root = root;
    this.api = root.getAttribute('data-api');
    this.settingsUrl = root.getAttribute('data-settings-url');
    this.targets = [];
    this.selected = new URLSearchParams(window.location.search).get('target');
    this.span = 365;
    this.addTb = 0;
    this.chart = null;
    this.load();
  }

  ForecastView.prototype.load = function () {
    var self = this;
    self.root.replaceChildren(el('div', { className: 'df-empty', text: 'Loading forecasts…' }));
    DF.getJson(self.api, { action: 'overview' }).then(function (data) {
      self.targets = data.targets;
      if (!self.selected || !self.find(self.selected)) {
        var first = data.targets.filter(function (t) { return t.status === 'filling'; })[0] || data.targets[0];
        self.selected = first ? first.id : null;
      }
      self.render(data);
    }).catch(function (error) {
      self.root.replaceChildren(el('div', { className: 'df-notice is-error' }, [
        DF.icon('exclamation-circle'),
        el('div', {}, [el('strong', { text: 'Could not load the forecasts. ' }), error.message])
      ]));
    });
  };

  ForecastView.prototype.find = function (id) {
    return this.targets.filter(function (t) { return t.id === id; })[0] || null;
  };

  ForecastView.prototype.render = function (data) {
    var self = this;
    var children = [];

    if (!data.saved) {
      children.push(el('div', { className: 'df-notice' }, [
        DF.icon('info-circle'),
        el('div', {}, [
          'Tracking the whole array and each pool with the default settings: a reading every hour, a trend over the last 180 days, and a warning 90 days before each fills. ',
          el('a', { href: self.settingsUrl, text: 'Change these or add disks and shares' }),
          '.'
        ])
      ]));
    }

    if (!self.targets.length) {
      children.push(el('div', { className: 'df-panel df-empty' }, [
        'Nothing is being tracked. ',
        el('a', { href: self.settingsUrl, text: 'Add a target in Settings' }),
        '.'
      ]));
      self.root.replaceChildren.apply(self.root, children);
      return;
    }

    self.list = el('div', { className: 'df-list', role: 'listbox', 'aria-label': 'Targets' }, self.targets.map(function (target) {
      return self.row(target);
    }));
    self.panel = el('section', { className: 'df-panel' });
    self.whatIfPanel = el('section', { className: 'df-panel' });
    children.push(el('div', { className: 'df-layout' }, [
      el('section', { className: 'df-panel df-list-panel' }, [
        el('div', { className: 'df-panel-head df-list-head' }, [
          el('h3', { className: 'df-panel-title', text: 'Targets' }),
          el('a', { className: 'df-button is-quiet is-small', href: self.settingsUrl, title: 'Choose what to track' }, [DF.icon('cog'), 'Settings'])
        ]),
        self.list
      ]),
      el('div', { className: 'df-main' }, [self.panel, self.whatIfPanel])
    ]));
    self.root.replaceChildren.apply(self.root, children);
    self.renderSelection();
  };

  ForecastView.prototype.row = function (target) {
    var self = this;
    var row = DF.targetRow(target, { detailed: true, selected: target.id === self.selected });
    row.setAttribute('role', 'option');
    row.setAttribute('tabindex', '0');
    row.setAttribute('aria-selected', target.id === self.selected ? 'true' : 'false');
    row.addEventListener('click', function () { self.select(target.id); });
    row.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        self.select(target.id);
      }
    });
    return row;
  };

  ForecastView.prototype.select = function (id) {
    if (this.selected === id) {
      return;
    }
    this.selected = id;
    Array.prototype.forEach.call(this.list.children, function (row) {
      var on = row.getAttribute('data-id') === id;
      row.classList.toggle('is-selected', on);
      row.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    this.renderSelection();
  };

  ForecastView.prototype.renderSelection = function () {
    this.renderPanel();
    this.renderWhatIf();
    this.loadSeries();
  };

  ForecastView.prototype.renderPanel = function () {
    var self = this;
    var target = self.find(self.selected);

    var segmented = el('div', { className: 'df-segmented', role: 'group', 'aria-label': 'History shown' }, SPANS.map(function (span) {
      return el('button', {
        type: 'button',
        'aria-pressed': span[0] === self.span ? 'true' : 'false',
        text: span[1],
        onclick: function () {
          self.span = span[0];
          Array.prototype.forEach.call(segmented.children, function (button, index) {
            button.setAttribute('aria-pressed', SPANS[index][0] === self.span ? 'true' : 'false');
          });
          self.loadSeries();
        }
      });
    }));

    self.legend = el('ul', { className: 'df-legend' });
    self.chartHolder = el('div', { className: 'df-chart' }, [el('div', { className: 'df-empty', text: 'Loading the graph…' })]);
    self.tableHolder = el('div');
    self.panel.replaceChildren(
      el('div', { className: 'df-panel-head' }, [el('h3', { className: 'df-panel-title', text: target.name + ': usage and forecast' }), segmented]),
      self.legend,
      self.chartHolder,
      self.tableHolder
    );
  };

  ForecastView.prototype.renderLegend = function (series) {
    var projected = series.projection.length > 0;
    var items = [
      el('li', {}, [el('span', { className: 'df-key' }), 'Used']),
      projected ? el('li', {}, [el('span', { className: 'df-key is-dashed' }), 'Estimate']) : null,
      projected ? el('li', {}, [el('span', { className: 'df-key is-band' }), 'Likely range']) : null,
      el('li', {}, [el('span', { className: 'df-key is-capacity' }), 'Capacity'])
    ].filter(Boolean);
    if (series.capacity !== series.baseCapacity) {
      items.push(el('li', {}, [el('span', { className: 'df-key is-capacity is-dashed' }), 'With ' + this.addTb + ' TB added']));
    }
    this.legend.replaceChildren.apply(this.legend, items);
  };

  ForecastView.prototype.loadSeries = function () {
    var self = this;
    var target = self.find(self.selected);
    var addTb = self.addTb;
    var requested = [self.selected, self.span, addTb].join('|');
    self.pending = requested;
    DF.getJson(self.api, { action: 'series', target: target.id, span: self.span, addTb: addTb }).then(function (series) {
      if (self.pending !== requested) {
        return;
      }
      self.showWhatIfResult(target, addTb, series.times, series.capacity);
      if (!series.history.length) {
        self.legend.replaceChildren();
        self.chartHolder.replaceChildren(el('div', { className: 'df-empty', text: 'No readings yet. The graph appears after the first few readings.' }));
        self.tableHolder.replaceChildren();
        return;
      }
      self.renderLegend(series);
      self.drawChart(self.chartHolder, series);
      self.tableHolder.replaceChildren(self.table(series));
    }).catch(function (error) {
      self.chartHolder.replaceChildren(el('div', { className: 'df-notice is-error', text: error.message }));
    });
  };

  /** The "what if I add a drive" card, for whichever target is selected. */
  ForecastView.prototype.renderWhatIf = function () {
    var self = this;
    var target = self.find(self.selected);
    var head = el('div', { className: 'df-panel-head' }, [el('h3', { className: 'df-panel-title', text: 'What if I add a drive to ' + target.name + '?' })]);

    var custom = el('input', {
      id: 'df-whatif-custom',
      type: 'number',
      min: '1',
      max: '10000',
      step: '1',
      placeholder: 'Other',
      inputmode: 'decimal',
      value: DRIVE_SIZES.indexOf(self.addTb) === -1 && self.addTb > 0 ? String(self.addTb) : null
    });
    var timer = null;
    var sizes = el('div', { className: 'df-sizes', role: 'group', 'aria-label': 'Drive size' });
    var choose = function (tb) {
      self.addTb = tb;
      Array.prototype.forEach.call(sizes.querySelectorAll('button[data-tb]'), function (button) {
        button.setAttribute('aria-pressed', parseFloat(button.getAttribute('data-tb')) === tb ? 'true' : 'false');
      });
      if (DRIVE_SIZES.indexOf(tb) !== -1 || tb === 0) {
        custom.value = '';
      }
      clear.hidden = tb === 0;
      self.loadSeries();
    };

    DRIVE_SIZES.forEach(function (tb) {
      sizes.appendChild(el('button', {
        type: 'button',
        className: 'df-size',
        'data-tb': tb,
        'aria-pressed': self.addTb === tb ? 'true' : 'false',
        text: tb + ' TB',
        onclick: function () { choose(self.addTb === tb ? 0 : tb); }
      }));
    });

    custom.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var tb = parseFloat(custom.value);
        choose(tb > 0 && tb <= 10000 ? tb : 0);
      }, 350);
    });

    var clear = el('button', { type: 'button', className: 'df-button is-quiet', hidden: self.addTb === 0, onclick: function () { choose(0); } }, [DF.icon('times'), 'Clear']);
    sizes.appendChild(el('label', { className: 'df-size-custom', for: 'df-whatif-custom' }, [custom, el('span', { text: 'TB' })]));
    sizes.appendChild(clear);

    self.whatIfResult = el('div', { className: 'df-whatif-result', 'aria-live': 'polite' });
    self.whatIfPanel.replaceChildren(
      head,
      el('p', { className: 'df-muted', style: 'margin:0 0 10px', text: 'Pick a drive size to see when ' + target.name + ' would fill with it.' }),
      sizes,
      self.whatIfResult
    );
  };

  ForecastView.prototype.showWhatIfResult = function (target, addTb, times, capacity) {
    var box = this.whatIfResult;
    var noForecast = !target.readings || times.status === 'collecting';
    var headline = function (value, detail) {
      return el('div', { className: 'df-whatif-headline df-num' }, [
        el('span', { className: 'df-muted', text: addTb ? 'With ' + addTb + ' TB more (' + DF.formatBytes(capacity) + ')' : 'Now, with ' + DF.formatBytes(target.size) }),
        el('strong', { text: value }),
        detail ? el('span', { text: detail }) : null
      ]);
    };

    if (noForecast) {
      var first = target.readings ? DF.rowStatus(target) : null;
      box.replaceChildren(headline('Full in: N/A', first ? first.text + (first.date ? ', ' + first.date : '') + '.' : 'There are no readings yet.'));
      return;
    }
    if (times.secondsToFull === null) {
      box.replaceChildren(headline('Full in: never (∞)', target.name + ' isn\'t growing, so it won\'t fill at this rate.'));
      return;
    }
    if (!addTb && times.status === 'full') {
      box.replaceChildren(headline('Full now', 'Pick a size to see how long a new drive would last.'));
      return;
    }
    if (!addTb) {
      box.replaceChildren(
        headline('Full in about ' + DF.formatDuration(times.secondsToFull), 'around ' + DF.formatDate(target.time + times.secondsToFull)),
        el('div', { className: 'df-muted df-num', text: 'Pick a size to compare.' })
      );
      return;
    }

    var gained = target.secondsToFull === null ? null : times.secondsToFull - target.secondsToFull;
    box.replaceChildren(
      headline('Full in about ' + DF.formatDuration(times.secondsToFull), 'around ' + DF.formatDate(target.time + times.secondsToFull) + (gained && gained > 0 ? ', ' + DF.formatDuration(gained) + ' later than now' : '')),
      el('div', { className: 'df-muted df-num', text: 'Likely ' + DF.rangeText(times) + '.' })
    );
  };

  ForecastView.prototype.drawChart = function (holder, series) {
    var style = getComputedStyle(this.root);
    var color = function (name) { return style.getPropertyValue(name).trim(); };
    var canvas = el('canvas', { role: 'img', 'aria-label': 'Usage history and forecast graph. The table below lists the projected values.' });
    holder.replaceChildren(canvas);
    if (this.chart) {
      this.chart.destroy();
    }

    var history = series.history.map(function (row) { return { x: row[0] * 1000, y: row[1] }; });
    var fast = series.projection.map(function (row) { return { x: row[0] * 1000, y: row[3] }; });
    var slow = series.projection.map(function (row) { return { x: row[0] * 1000, y: row[2] }; });
    var estimate = series.projection.map(function (row) { return { x: row[0] * 1000, y: row[1] }; });
    var start = history[0].x;
    var end = fast.length ? fast[fast.length - 1].x : history[history.length - 1].x;
    var line = function (y) { return [{ x: start, y: y }, { x: end, y: y }]; };
    var added = series.capacity !== series.baseCapacity;

    var values = history.map(function (p) { return p.y; }).concat(slow.map(function (p) { return p.y; }));
    var low = Math.min.apply(null, values);
    var padding = Math.max((series.capacity - low) * 0.08, series.capacity * 0.01);
    var now = series.now * 1000;

    var nowLine = {
      id: 'dfNow',
      afterDatasetsDraw: function (chart) {
        var x = chart.scales.x.getPixelForValue(now);
        var area = chart.chartArea;
        if (x < area.left || x > area.right) {
          return;
        }
        var ctx = chart.ctx;
        ctx.save();
        ctx.strokeStyle = color('--df-text-3');
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(Math.round(x) + 0.5, area.top);
        ctx.lineTo(Math.round(x) + 0.5, area.bottom);
        ctx.stroke();
        ctx.fillStyle = color('--df-text-2');
        ctx.font = '600 11px ' + style.fontFamily;
        var nearRight = area.right - x < 40;
        ctx.textAlign = nearRight ? 'right' : 'left';
        ctx.fillText('Now', Math.round(x) + (nearRight ? -5 : 5), area.top + 12);
        ctx.restore();
      }
    };

    var ticks = timeTicks(start, end);
    var datasets = [
      { label: 'Used', data: history, borderColor: color('--df-accent'), borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: color('--df-accent'), pointHoverBorderColor: color('--df-card'), pointHoverBorderWidth: 2, tension: 0, fill: false, order: 1 },
      { label: 'Fast case', data: fast, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: false, order: 4 },
      { label: 'Slow case', data: slow, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: '-1', backgroundColor: color('--df-band'), order: 4 },
      { label: 'Estimate', data: estimate, borderColor: color('--df-accent'), borderWidth: 2, borderDash: [6, 4], pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: color('--df-accent'), pointHoverBorderColor: color('--df-card'), pointHoverBorderWidth: 2, fill: false, order: 2 },
      { label: 'Capacity', data: line(series.baseCapacity), borderColor: color('--df-text-3'), borderWidth: 1, pointRadius: 0, pointHoverRadius: 0, fill: false, order: 3 }
    ];
    if (added) {
      datasets.push({ label: 'With drive added', data: line(series.capacity), borderColor: color('--df-text-2'), borderWidth: 1, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 0, fill: false, order: 3 });
    }

    this.chart = new window.Chart(canvas, {
      type: 'line',
      data: { datasets: datasets },
      options: {
        animation: false,
        responsive: true,
        maintainAspectRatio: false,
        parsing: false,
        normalized: true,
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        layout: { padding: { top: 4, right: 8 } },
        scales: {
          x: {
            type: 'linear',
            min: start,
            max: end,
            grid: { color: color('--df-grid'), drawTicks: false },
            border: { color: color('--df-grid') },
            afterBuildTicks: function (axis) {
              axis.ticks = ticks.values.map(function (value) { return { value: value }; });
            },
            ticks: { color: color('--df-text-2'), padding: 8, autoSkipPadding: 12, maxRotation: 0, callback: ticks.label }
          },
          y: {
            suggestedMin: Math.max(0, low - padding),
            suggestedMax: series.capacity,
            grid: { color: color('--df-grid'), drawTicks: false },
            border: { display: false },
            ticks: { color: color('--df-text-2'), maxTicksLimit: 6, padding: 8, callback: function (value) { return DF.formatBytes(value); } }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: color('--df-card'),
            titleColor: color('--df-text'),
            bodyColor: color('--df-text'),
            borderColor: color('--df-border'),
            borderWidth: 1,
            padding: 10,
            boxPadding: 4,
            usePointStyle: true,
            filter: function (item) {
              return item.datasetIndex === 0 || item.datasetIndex === 2 || item.datasetIndex === 3;
            },
            callbacks: {
              title: function (items) {
                return items.length ? DF.formatDate(items[0].parsed.x / 1000) : '';
              },
              label: function (item) {
                if (item.datasetIndex === 2) {
                  var upper = fast[item.dataIndex];
                  return 'Likely range: ' + DF.formatBytes(item.parsed.y) + ' – ' + DF.formatBytes(upper ? upper.y : item.parsed.y);
                }
                return item.dataset.label + ': ' + DF.formatBytes(item.parsed.y);
              },
              labelPointStyle: function () {
                return { pointStyle: 'circle', rotation: 0 };
              },
              labelColor: function (item) {
                var fill = item.datasetIndex === 2 ? color('--df-band') : color('--df-accent');
                return { borderColor: fill, backgroundColor: fill };
              }
            }
          }
        }
      },
      plugins: [nowLine]
    });
  };

  ForecastView.prototype.table = function (series) {
    if (!series.milestones.length) {
      return el('div');
    }
    var capacity = series.capacity;
    var cell = function (bytes) {
      return bytes >= capacity ? 'Full' : DF.formatBytes(bytes);
    };
    var rows = series.milestones.map(function (m) {
      var label = m.days < 365 ? Math.round(m.days / 30.44) + ' months' : (m.days / 365 >= 2 ? Math.round(m.days / 365) + ' years' : '1 year');
      return el('tr', {}, [
        el('td', { text: label }),
        el('td', { text: DF.formatDate(m.time) }),
        el('td', { text: cell(m.estimate) }),
        el('td', { text: cell(m.slow) + ' – ' + cell(m.fast) })
      ]);
    });

    return el('details', { className: 'df-table-toggle' }, [
      el('summary', { text: 'Projected usage table' }),
      el('table', { className: 'df-table' }, [
        el('thead', {}, [el('tr', {}, [
          el('th', { text: 'In' }),
          el('th', { text: 'Date' }),
          el('th', { text: 'Estimate' }),
          el('th', { text: 'Likely range' })
        ])]),
        el('tbody', {}, rows)
      ]),
      el('p', { className: 'df-faint', text: 'Capacity ' + DF.formatBytes(capacity) + '. "Full" means the projection reaches capacity by then.' })
    ]);
  };

  /**
   * Axis ticks on calendar boundaries (days, or the first of a month) with at most eight
   * ticks, and a label to match: "14 Apr", "Apr 2027" or "2027".
   */
  function timeTicks(startMs, endMs) {
    var spanDays = (endMs - startMs) / 86400000;
    var values = [];
    if (spanDays <= 90) {
      var dayStep = [1, 2, 7, 14].filter(function (s) { return spanDays / s <= 8; })[0] || 14;
      var day = new Date(startMs);
      day.setHours(0, 0, 0, 0);
      if (day.getTime() < startMs) {
        day.setDate(day.getDate() + 1);
      }
      for (; day.getTime() <= endMs; day.setDate(day.getDate() + dayStep)) {
        values.push(day.getTime());
      }
      return { values: values, label: function (value) { return DF.formatShortDate(value / 1000); } };
    }

    var spanMonths = spanDays / 30.44;
    var monthStep = [1, 2, 3, 6, 12, 24, 60].filter(function (s) { return spanMonths / s <= 8; })[0] || 120;
    var first = new Date(startMs);
    var index = first.getFullYear() * 12 + first.getMonth() + 1;
    index = Math.ceil(index / monthStep) * monthStep;
    for (var month = new Date(Math.floor(index / 12), index % 12, 1); month.getTime() <= endMs; month = new Date(month.getFullYear(), month.getMonth() + monthStep, 1)) {
      values.push(month.getTime());
    }
    return {
      values: values,
      label: function (value) {
        return monthStep >= 12 ? String(new Date(value).getFullYear()) : DF.formatMonth(value / 1000);
      }
    };
  }

  function start() {
    var root = document.getElementById('df-forecast');
    if (root && window.Chart) {
      new ForecastView(root);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
