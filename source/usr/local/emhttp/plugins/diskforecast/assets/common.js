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

  function typeLabel(target) {
    switch (target.type) {
      case 'array': return 'Whole array';
      case 'pool': return 'Pool · ' + (target.members[0] || '');
      case 'share': return 'Share · ' + (target.members[0] || '');
      default: return target.members.length === 1 ? 'Disk · ' + target.members[0] : target.members.length + ' disks';
    }
  }

  /** The chip, meter colour and headline for a target's forecast. */
  function describe(target) {
    var full = target.status === 'full';
    var info = {
      chip: null,
      meter: full ? 'is-critical' : (target.warning ? 'is-warning' : ''),
      label: '',
      value: '',
      date: '',
      facts: []
    };

    if (target.warning && !full) {
      info.chip = { kind: 'is-warning', icon: 'exclamation-triangle', text: 'Within ' + warnLabel(target.warnDays) };
    }

    if (!target.readings) {
      info.label = 'Waiting for the first reading';
      info.value = 'No data yet';
      info.date = 'A reading is taken every ' + intervalLabel(target.intervalMinutes) + '.';
      return info;
    }

    if (full) {
      info.chip = { kind: 'is-critical', icon: 'times-circle', text: 'Full' };
      info.label = 'No free space left';
      info.value = 'Full';
      return info;
    }

    if (target.status === 'collecting') {
      info.chip = { kind: 'is-neutral', icon: 'hourglass-half', text: 'Collecting' };
      info.label = 'First forecast after 7 days of readings';
      info.value = 'Collecting';
      info.date = target.readings + ' readings over ' + formatDuration(target.time - target.firstReading) + ' so far.';
      return info;
    }

    if (target.status === 'not-filling') {
      info.label = 'Usage is flat or shrinking';
      info.value = 'Not filling';
      info.date = target.windowDays === 0 ? 'Across all its history.' : 'Over the last ' + windowLabel(target.windowDays) + '.';
      return info;
    }

    info.label = 'Full in about';
    info.value = formatDuration(target.secondsToFull);
    info.date = 'around ' + formatDate(target.time + target.secondsToFull);
    info.facts.push(['Likely range: ', rangeText(target)]);
    return info;
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
    typeLabel: typeLabel,
    describe: describe,
    rangeText: rangeText,
    usedFraction: usedFraction,
    DAY: DAY,
    YEAR: YEAR
  };
})();
