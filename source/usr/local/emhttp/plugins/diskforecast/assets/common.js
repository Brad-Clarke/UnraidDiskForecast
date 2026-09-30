/* Disk Forecast: helpers shared by the forecast, settings and dashboard views. */
(function () {
  'use strict';

  var MINUTE = 60;
  var HOUR = 3600;
  var DAY = 86400;
  var MONTH = 2629800;
  var YEAR = 31557600;
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  /** Builds an element. Text is always set as text, never parsed as HTML. */
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) {
        return;
      }
      if (key === 'text') {
        node.textContent = value;
      } else if (key === 'className') {
        node.className = value;
      } else if (key.indexOf('on') === 0) {
        node.addEventListener(key.slice(2), value);
      } else if (value === true) {
        node.setAttribute(key, '');
      } else {
        node.setAttribute(key, String(value));
      }
    });
    (children || []).forEach(function (child) {
      if (child === null || child === undefined || child === false) {
        return;
      }
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function icon(name) {
    return el('i', { className: 'fa fa-' + name, 'aria-hidden': 'true' });
  }

  function request(url, options) {
    return fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}))
      .then(function (response) {
        return response.json().catch(function () {
          return {};
        }).then(function (body) {
          if (!response.ok) {
            var error = new Error(body.error || (body.errors && body.errors[0]) || 'The server did not answer (' + response.status + ').');
            error.body = body;
            throw error;
          }
          return body;
        });
      });
  }

  function getJson(base, params) {
    return request(base + '?' + new URLSearchParams(params).toString());
  }

  function postForm(base, action, fields) {
    return request(base + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(fields).toString()
    });
  }

  /** Decimal bytes, as Unraid shows them. */
  function formatBytes(bytes) {
    if (bytes === null || bytes === undefined || !isFinite(bytes)) {
      return '–';
    }
    var units = [['PB', 1e15], ['TB', 1e12], ['GB', 1e9], ['MB', 1e6], ['KB', 1e3]];
    for (var i = 0; i < units.length; i++) {
      if (Math.abs(bytes) >= units[i][1]) {
        var value = bytes / units[i][1];
        return (Math.abs(value) >= 100 ? value.toFixed(0) : value.toFixed(1)) + ' ' + units[i][0];
      }
    }
    return Math.round(bytes) + ' B';
  }

  /** The two largest units of a duration: "2y 7mo", "3mo 12d", "5d 4h", "3h 20m". */
  function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || !isFinite(seconds)) {
      return '–';
    }
    var units = [['y', YEAR], ['mo', MONTH], ['d', DAY], ['h', HOUR], ['m', MINUTE]];
    var remaining = Math.max(0, seconds);
    var parts = [];
    for (var i = 0; i < units.length; i++) {
      var count = Math.floor(remaining / units[i][1]);
      if (parts.length === 0 && count === 0) {
        continue;
      }
      if (count > 0) {
        parts.push(count + units[i][0]);
      }
      remaining -= count * units[i][1];
      if (parts.length === 2 || count === 0) {
        break;
      }
    }
    return parts.length ? parts.join(' ') : 'under a minute';
  }

  /** A date such as "14 Apr 2029" from Unix seconds. */
  function formatDate(time) {
    var date = new Date(time * 1000);
    return date.getDate() + ' ' + MONTHS[date.getMonth()] + ' ' + date.getFullYear();
  }

  function formatMonth(time) {
    var date = new Date(time * 1000);
    return MONTHS[date.getMonth()] + ' ' + date.getFullYear();
  }

  function formatShortDate(time) {
    var date = new Date(time * 1000);
    return date.getDate() + ' ' + MONTHS[date.getMonth()];
  }

  function formatRate(perMonth) {
    if (perMonth === null || perMonth === undefined) {
      return '–';
    }
    return (perMonth < 0 ? '−' : '') + formatBytes(Math.abs(perMonth)) + ' a month';
  }

  function intervalLabel(minutes) {
    if (minutes < 60) {
      return minutes + ' minutes';
    }
    if (minutes === 60) {
      return '1 hour';
    }
    if (minutes < 1440) {
      return (minutes / 60) + ' hours';
    }
    return '1 day';
  }

  function windowLabel(days) {
    if (days === 0) {
      return 'All history';
    }
    if (days % 365 === 0) {
      return days === 365 ? '1 year' : (days / 365) + ' years';
    }
    return days + ' days';
  }

  function warnLabel(days) {
    if (days === 0) {
      return 'Never';
    }
    if (days % 365 === 0) {
      return days === 365 ? '1 year' : (days / 365) + ' years';
    }
    return days + ' days';
  }

  /**
   * The headline of a target's forecast: the time to full (or state), its colour, the date,
   * and the extra lines the Forecast page shows under the meter.
   */
  function rowStatus(target) {
    var status = { text: '', kind: '', icon: null, date: '', notes: [] };

    if (!target.readings) {
      status.text = 'Waiting for readings';
      status.date = 'Every ' + intervalLabel(target.intervalMinutes).replace(/^1 /, '');
      return status;
    }

    if (target.status === 'full') {
      status.text = 'Full';
      status.kind = 'is-critical';
      status.icon = 'times-circle';
      status.date = 'No free space left';
      return status;
    }

    if (target.status === 'collecting') {
      status.text = 'Collecting';
      status.date = formatDuration(target.time - target.firstReading) + ' so far';
      status.notes.push(['First forecast after 7 days of readings.', null]);
      return status;
    }

    if (target.status === 'not-filling') {
      status.text = 'Not filling';
      status.date = target.windowDays === 0 ? 'across all history' : 'over the last ' + windowLabel(target.windowDays);
      return status;
    }

    status.text = 'Full in ' + formatDuration(target.secondsToFull);
    status.date = 'around ' + formatDate(target.time + target.secondsToFull);
    if (target.warning) {
      status.kind = 'is-warning';
      status.icon = 'exclamation-triangle';
      status.notes.push(['Within your ' + warnLabel(target.warnDays).replace(' days', '-day').replace(' year', '-year') + ' warning window.', null]);
    }
    if (target.growthPerMonth > 0) {
      status.notes.push(['Expected growth: ', formatRate(target.growthPerMonth)]);
    }
    if (!target.calibrated && target.windowDays > 0) {
      status.notes.push(['Rough estimate until there are ' + Math.round((target.windowDays + 30) / 30.44) + ' months of readings.', null]);
    }
    return status;
  }

  /**
   * One target as a row: name and time to full, a meter, and how much is used. The detailed
   * row (Forecast page) adds the kind, the date, the likely range and notes.
   *
   * @param {object} target Target summary from the overview.
   * @param {object} options tag ('a' or 'div'), href, detailed, selected.
   */
  function targetRow(target, options) {
    var status = rowStatus(target);
    var fraction = usedFraction(target);
    var detailed = !!options.detailed;
    var line = function (className, left, right) {
      return el('div', { className: 'df-row-line ' + className }, [left, right]);
    };

    var children = [
      line('',
        el('strong', { className: 'df-row-name', text: target.name }),
        el('span', { className: 'df-row-when df-num ' + status.kind }, [status.icon ? icon(status.icon) : null, status.icon ? ' ' : null, status.text]))
    ];

    if (detailed) {
      children.push(line('df-row-sub', el('span', { text: target.description }), el('span', { className: 'df-num', text: status.date })));
    }

    if (target.size) {
      children.push(el('div', {
        className: 'df-meter ' + status.kind,
        role: 'meter',
        'aria-valuemin': 0,
        'aria-valuemax': 100,
        'aria-valuenow': Math.round(fraction * 100),
        'aria-label': target.name + ' used'
      }, [el('span', { style: 'width:' + (fraction * 100).toFixed(1) + '%' })]));
      children.push(line('df-row-sub',
        el('span', { className: 'df-num', text: formatBytes(target.used) + ' of ' + formatBytes(target.size) + (detailed ? ' · ' + Math.round(fraction * 100) + '%' : '') }),
        detailed && target.status === 'filling' ? el('span', { className: 'df-num', text: 'Likely ' + rangeText(target) }) : null));
    }

    if (detailed) {
      status.notes.forEach(function (note) {
        children.push(el('div', { className: 'df-row-note' }, [note[0], note[1] ? el('strong', { className: 'df-num', text: note[1] }) : null]));
      });
    }

    var attrs = { className: 'df-row' + (options.selected ? ' is-selected' : ''), 'data-id': target.id };
    if (options.href) {
      attrs.href = options.href;
    }
    return el(options.tag || 'div', attrs, children);
  }

  /** "1y 1mo – 3y 9mo"; anything past ten years reads as "over 10y". */
  function rangeText(times) {
    var part = function (seconds) {
      return seconds === null || seconds > 10 * YEAR ? 'over 10y' : formatDuration(seconds);
    };
    return part(times.earliestSeconds) + ' – ' + part(times.latestSeconds);
  }

  function usedFraction(target) {
    if (!target.size) {
      return 0;
    }
    return Math.max(0, Math.min(1, target.used / target.size));
  }

  window.DiskForecast = {
    el: el,
    icon: icon,
    getJson: getJson,
    postForm: postForm,
    formatBytes: formatBytes,
    formatDuration: formatDuration,
    formatDate: formatDate,
    formatMonth: formatMonth,
    formatShortDate: formatShortDate,
    formatRate: formatRate,
    intervalLabel: intervalLabel,
    windowLabel: windowLabel,
    warnLabel: warnLabel,
    rowStatus: rowStatus,
    targetRow: targetRow,
    rangeText: rangeText,
    usedFraction: usedFraction,
    DAY: DAY,
    YEAR: YEAR
  };
})();
