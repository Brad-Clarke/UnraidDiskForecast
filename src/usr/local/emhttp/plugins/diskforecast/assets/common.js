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

  /**
   * Unraid's display formats: the date format from Settings > Date and Time (strftime, such as
   * "%A, %e %B %Y", or "%c" for the system's own) and the number format from Settings >
   * Display (the decimal mark, then the thousands separator if any, such as "," + ".").
   */
  var display = { date: '%c', decimal: '.', group: ',' };
  var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  /** Takes Unraid's formats from a view's root element (data-date-format, data-number-format). */
  function configure(root) {
    var number = root.getAttribute('data-number-format');
    display.date = root.getAttribute('data-date-format') || '%c';
    if (number) {
      display.decimal = number.charAt(0) || '.';
      display.group = number.charAt(1);
    }
  }

  /** A number with Unraid's decimal mark and thousands separator. */
  function formatNumber(value, decimals) {
    var parts = Math.abs(value).toFixed(decimals || 0).split('.');
    var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, display.group);
    return (value < 0 ? '−' : '') + whole + (parts[1] ? display.decimal + parts[1] : '');
  }

  /** Decimal bytes, as Unraid shows them: "38.2 TB" (or "38,2 TB" with a decimal comma). */
  function formatBytes(bytes) {
    if (bytes === null || bytes === undefined || !isFinite(bytes)) {
      return '–';
    }
    var units = [['PB', 1e15], ['TB', 1e12], ['GB', 1e9], ['MB', 1e6], ['KB', 1e3]];
    for (var i = 0; i < units.length; i++) {
      if (Math.abs(bytes) >= units[i][1]) {
        var value = bytes / units[i][1];
        return formatNumber(value, Math.abs(value) >= 100 ? 0 : 1) + ' ' + units[i][0];
      }
    }
    return formatNumber(bytes, 0) + ' B';
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

  /** Unraid's date format without the leading weekday, or '' for the system's own ("%c"). */
  function datePattern() {
    var pattern = display.date.replace(/^%[Aa],?\s*/, '');
    return pattern === '%c' || /%[^YmdeBb]/.test(pattern) ? '' : pattern;
  }

  /**
   * A date from Unix seconds in Unraid's format without the weekday: "12 July 2028",
   * "July 12, 2028", "2028-07-12". The system format uses the browser's own style.
   */
  function formatDate(time) {
    var date = new Date(time * 1000);
    var pattern = datePattern();
    if (!pattern) {
      return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var tokens = {
      Y: String(date.getFullYear()),
      m: pad(date.getMonth() + 1),
      d: pad(date.getDate()),
      e: String(date.getDate()),
      B: MONTH_NAMES[date.getMonth()],
      b: MONTHS[date.getMonth()]
    };
    return pattern.replace(/%([YmdeBb])/g, function (match, token) { return tokens[token]; });
  }

  /** Whether Unraid's date format puts the month before the day. */
  function monthFirst() {
    var pattern = datePattern();
    if (!pattern) {
      return new Date(2000, 0, 31).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }).search(/31/) > 0;
    }
    return pattern.search(/%[mBb]/) < pattern.search(/%[de]/);
  }

  /** A month for graph axes: "Jul 2027". */
  function formatMonth(time) {
    var date = new Date(time * 1000);
    return MONTHS[date.getMonth()] + ' ' + date.getFullYear();
  }

  /** A day for graph axes, in the order Unraid's date format uses: "12 Jul" or "Jul 12". */
  function formatShortDate(time) {
    var date = new Date(time * 1000);
    return monthFirst() ? MONTHS[date.getMonth()] + ' ' + date.getDate() : date.getDate() + ' ' + MONTHS[date.getMonth()];
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
   * How much of the history needed by the given time has been collected: a whole percentage,
   * held below 100 until the stage is reached, and "3d 2h of the 7d of readings needed".
   */
  function readingProgress(target, until) {
    var needed = until - target.firstReading;
    var done = Math.min(needed, Math.max(0, target.time - target.firstReading));
    return {
      percent: Math.min(99, Math.floor(done / needed * 100)),
      text: formatDuration(done) + ' of the ' + formatDuration(needed) + ' of readings needed'
    };
  }

  /**
   * The headline of a target's forecast: the time to full (or state), its colour, the date,
   * a hint shown on hover, and the extra lines the Forecast page shows under the meter. A
   * note is [text, bold value, hint].
   */
  function rowStatus(target) {
    var status = { text: '', kind: '', icon: null, date: '', hint: null, notes: [] };

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
      var first = readingProgress(target, target.forecastFrom);
      var left = target.forecastFrom - target.time;
      status.text = left > 0 ? 'First forecast in ' + formatDuration(left) : 'First forecast soon';
      status.date = left > 0 ? 'around ' + formatDate(target.forecastFrom) : '';
      status.hint = first.text + '.';
      status.notes.push(['Collecting · ' + first.percent + '%', null, status.hint]);
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
    if (!target.calibrated && target.windowDays > 0 && target.calibratedFrom) {
      var calibration = readingProgress(target, target.calibratedFrom);
      status.notes.push(['Rough estimate · ' + calibration.percent + '%', null, calibration.text +
        (target.calibratedFrom > target.time ? '; firmer from around ' + formatDate(target.calibratedFrom) + '.' : '.')]);
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
        el('span', { className: 'df-row-when df-num ' + status.kind, title: status.hint }, [status.icon ? icon(status.icon) : null, status.icon ? ' ' : null, status.text]))
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
        children.push(el('div', { className: 'df-row-note' }, [
          note[2] ? el('span', { className: 'df-hint df-num', title: note[2], text: note[0] }) : note[0],
          note[1] ? el('strong', { className: 'df-num', text: note[1] }) : null
        ]));
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
    configure: configure,
    formatNumber: formatNumber,
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
