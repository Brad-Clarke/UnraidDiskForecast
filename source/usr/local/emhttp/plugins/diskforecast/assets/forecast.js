/* Disk Forecast: the forecast view (cards, graph, projection table). */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;
  var SPANS = [[30, '30D'], [90, '90D'], [365, '1Y'], [0, 'All']];

  function ForecastView(root) {
    this.root = root;
    this.api = root.getAttribute('data-api');
    this.settingsUrl = root.getAttribute('data-settings-url');
    this.targets = [];
    this.selected = null;
    this.span = 365;
    this.chart = null;
    this.load();
    document.addEventListener('diskforecast:saved', this.load.bind(this));
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

  /** A link to the Settings tab: switches tab in place when the tabs are on this page. */
  ForecastView.prototype.settingsLink = function (text) {
    var self = this;
    return el('a', {
      href: self.settingsUrl,
      text: text,
      onclick: function (event) {
        var tab = document.getElementById('tab2');
        if (tab && tab.type === 'radio') {
          event.preventDefault();
          tab.click();
        }
      }
    });
  };

  ForecastView.prototype.find = function (id) {
    return this.targets.filter(function (t) { return t.id === id; })[0] || null;
  };

  ForecastView.prototype.render = function (data) {
    var self = this;
    var children = [];

    if (!data.dataDirAvailable) {
      children.push(el('div', { className: 'df-notice is-warning' }, [
        DF.icon('exclamation-triangle'),
        el('div', {}, [
          el('strong', { text: 'Readings are paused. ' }),
          'The readings folder ' + data.dataDir + ' is not available, usually because the array is stopped. Readings resume when it is back.'
        ])
      ]));
    }

    if (!data.saved) {
      children.push(el('div', { className: 'df-notice' }, [
        DF.icon('info-circle'),
        el('div', {}, [
          'Tracking the array and each pool with the default settings. ',
          self.settingsLink('Choose what to track'),
          ' to add disks or shares, change how often readings are taken, or get a warning before something fills.'
        ])
      ]));
    }

    if (!self.targets.length) {
      children.push(el('div', { className: 'df-panel df-empty' }, [
        'Nothing is being tracked. ',
        self.settingsLink('Add a target in Settings'),
        '.'
      ]));
      self.root.replaceChildren.apply(self.root, children);
      return;
    }

    self.cards = el('div', { className: 'df-cards' }, self.targets.map(function (t) { return self.card(t); }));
    self.panel = el('section', { className: 'df-panel', 'aria-live': 'polite' });
    children.push(self.cards, self.panel);
    self.root.replaceChildren.apply(self.root, children);
    self.renderPanel();
  };

  ForecastView.prototype.card = function (target) {
    var self = this;
    var info = DF.describe(target);
    var fraction = DF.usedFraction(target);

    var head = el('div', { className: 'df-card-head' }, [
      el('div', { style: 'min-width:0' }, [
        el('h3', { className: 'df-card-title', text: target.name }),
        el('div', { className: 'df-card-kind', text: DF.typeLabel(target) })
      ]),
      info.chip ? el('span', { className: 'df-chip ' + info.chip.kind }, [DF.icon(info.chip.icon), info.chip.text]) : null
    ]);

    var usage = target.size ? el('div', { className: 'df-usage' }, [
      el('div', { className: 'df-usage-text df-num' }, [
        el('span', {}, [el('strong', { text: DF.formatBytes(target.used) }), ' of ' + DF.formatBytes(target.size) + ' used']),
        el('span', { text: Math.round(fraction * 100) + '%' })
      ]),
      el('div', {
        className: 'df-meter ' + info.meter,
        role: 'meter',
        'aria-valuemin': 0,
        'aria-valuemax': 100,
        'aria-valuenow': Math.round(fraction * 100),
        'aria-label': target.name + ' used'
      }, [el('span', { style: 'width:' + (fraction * 100).toFixed(1) + '%' })])
    ]) : null;

    var headline = el('div', { className: 'df-headline' }, [
      el('span', { className: 'df-headline-label', text: info.label }),
      el('span', { className: 'df-headline-value df-num', text: info.value }),
      info.date ? el('span', { className: 'df-headline-date', text: info.date }) : null
    ]);

    var facts = info.facts.map(function (fact) {
      return el('div', {}, [fact[0], el('strong', { className: 'df-num', text: fact[1] })]);
    });
    if (target.status === 'filling' && target.growthPerMonth > 0) {
      facts.push(el('div', {}, ['Growing about ', el('strong', { className: 'df-num', text: DF.formatRate(target.growthPerMonth) })]));
    }
    if (target.status === 'filling' && !target.calibrated) {
      facts.push(el('div', { className: 'df-faint', text: 'Early estimate: it sharpens once there is more history.' }));
    }

    var card = el('article', {
      className: 'df-card' + (target.id === self.selected ? ' is-selected' : ''),
      tabindex: 0,
      'aria-pressed': target.id === self.selected ? 'true' : 'false',
      'data-id': target.id,
      onclick: function () { self.select(target.id); },
      onkeydown: function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && event.target === card) {
          event.preventDefault();
          self.select(target.id);
        }
      }
    }, [head, usage, headline, facts.length ? el('div', { className: 'df-facts' }, facts) : null]);

    if (target.status === 'filling' || target.status === 'full') {
      card.appendChild(self.whatIf(target));
    }
    return card;
  };

  ForecastView.prototype.whatIf = function (target) {
    var self = this;
    var result = el('div', { className: 'df-whatif-result df-num', 'aria-live': 'polite' });
    var timer = null;
    var inputId = 'df-whatif-' + target.id;
    var input = el('input', {
      id: inputId,
      type: 'number',
      min: '0.5',
      max: '10000',
      step: '0.5',
      placeholder: '20',
      inputmode: 'decimal',
      oninput: function () {
        clearTimeout(timer);
        var tb = parseFloat(input.value);
        if (!(tb > 0)) {
          result.textContent = '';
          return;
        }
        result.textContent = 'Working it out…';
        timer = setTimeout(function () {
          DF.getJson(self.api, { action: 'whatif', target: target.id, addTb: tb }).then(function (times) {
            if (String(tb) !== String(parseFloat(input.value))) {
              return;
            }
            result.textContent = times.secondsToFull === null
              ? 'Would not fill at the current rate.'
              : 'Full in about ' + DF.formatDuration(times.secondsToFull) + ' (' + DF.rangeText(times) + ').';
          }).catch(function (error) {
            result.textContent = error.message;
          });
        }, 350);
      }
    });

    return el('div', {
      className: 'df-whatif',
      onclick: function (event) { event.stopPropagation(); },
      onkeydown: function (event) { event.stopPropagation(); }
    }, [el('label', { for: inputId, text: 'If I add' }), input, el('span', { text: 'TB' }), result]);
  };

  ForecastView.prototype.select = function (id) {
    if (this.selected === id) {
      return;
    }
    this.selected = id;
    Array.prototype.forEach.call(this.cards.children, function (card) {
      var on = card.getAttribute('data-id') === id;
      card.classList.toggle('is-selected', on);
      card.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    this.renderPanel();
  };

  ForecastView.prototype.renderPanel = function () {
    var self = this;
    var target = self.find(self.selected);
    if (!target) {
      return;
    }

    var segmented = el('div', { className: 'df-segmented', role: 'group', 'aria-label': 'History shown' }, SPANS.map(function (span) {
      return el('button', {
        type: 'button',
        'aria-pressed': span[0] === self.span ? 'true' : 'false',
        text: span[1],
        onclick: function () {
          self.span = span[0];
          self.renderPanel();
        }
      });
    }));

    var legend = el('ul', { className: 'df-legend' }, [
      el('li', {}, [el('span', { className: 'df-key' }), 'Used']),
      el('li', {}, [el('span', { className: 'df-key is-dashed' }), 'Estimate']),
      el('li', {}, [el('span', { className: 'df-key is-band' }), 'Likely range']),
      el('li', {}, [el('span', { className: 'df-key is-capacity' }), 'Capacity'])
    ]);

    var canvasHolder = el('div', { className: 'df-chart' }, [el('div', { className: 'df-empty', text: 'Loading the graph…' })]);
    var table = el('div');
    self.panel.replaceChildren(
      el('div', { className: 'df-panel-head' }, [el('h3', { className: 'df-panel-title', text: target.name + ': usage and forecast' }), segmented]),
      legend,
      canvasHolder,
      table
    );

    var requested = self.selected + '|' + self.span;
    self.pending = requested;
    DF.getJson(self.api, { action: 'series', target: target.id, span: self.span }).then(function (series) {
      if (self.pending !== requested) {
        return;
      }
      if (!series.history.length) {
        canvasHolder.replaceChildren(el('div', { className: 'df-empty', text: 'No readings yet. The graph appears after the first few readings.' }));
        return;
      }
      self.drawChart(canvasHolder, series);
      table.replaceChildren(self.table(series));
    }).catch(function (error) {
      canvasHolder.replaceChildren(el('div', { className: 'df-notice is-error', text: error.message }));
    });
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
    var capacity = [{ x: start, y: series.capacity }, { x: end, y: series.capacity }];

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
        ctx.textAlign = 'left';
        ctx.fillText('Now', Math.round(x) + 5, area.top + 12);
        ctx.restore();
      }
    };

    var ticks = timeTicks(start, end);

    this.chart = new window.Chart(canvas, {
      type: 'line',
      data: {
        datasets: [
          { label: 'Used', data: history, borderColor: color('--df-accent'), borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: color('--df-accent'), pointHoverBorderColor: color('--df-card'), pointHoverBorderWidth: 2, tension: 0, fill: false, order: 1 },
          { label: 'Fast case', data: fast, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: false, order: 4 },
          { label: 'Slow case', data: slow, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: '-1', backgroundColor: color('--df-band'), order: 4 },
          { label: 'Estimate', data: estimate, borderColor: color('--df-accent'), borderWidth: 2, borderDash: [6, 4], pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: color('--df-accent'), pointHoverBorderColor: color('--df-card'), pointHoverBorderWidth: 2, fill: false, order: 2 },
          { label: 'Capacity', data: capacity, borderColor: color('--df-text-3'), borderWidth: 1, pointRadius: 0, pointHoverRadius: 0, fill: false, order: 3 }
        ]
      },
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
