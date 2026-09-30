/* Disk Forecast: the settings view (what to track). */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;
  var TYPES = [
    ['array', 'Whole array'],
    ['disks', 'Disks or pools'],
    ['share', 'A share']
  ];

  function SettingsView(root) {
    this.root = root;
    this.api = root.getAttribute('data-api');
    this.csrf = root.getAttribute('data-csrf') || window.csrf_token || '';
    this.doneUrl = root.getAttribute('data-done-url');
    this.forecastUrl = root.getAttribute('data-forecast-url');
    this.load();
  }

  SettingsView.prototype.load = function () {
    var self = this;
    self.root.replaceChildren(el('div', { className: 'df-empty', text: 'Loading settings…' }));
    DF.getJson(self.api, { action: 'settings' }).then(function (data) {
      self.inventory = data.inventory;
      self.options = data.options;
      self.saved = data.saved;
      self.dataDir = data.dataDir;
      self.history = data.history;
      self.state = JSON.parse(JSON.stringify(data.settings));
      self.original = JSON.stringify(data.settings);
      self.render();
    }).catch(function (error) {
      self.root.replaceChildren(el('div', { className: 'df-notice is-error' }, [
        DF.icon('exclamation-circle'),
        el('div', {}, [el('strong', { text: 'Could not load the settings. ' }), error.message])
      ]));
    });
  };

  SettingsView.prototype.render = function (messages) {
    var self = this;
    var children = [];
    if (messages) {
      children.push(messages);
    } else if (!self.saved) {
      children.push(el('div', { className: 'df-notice' }, [
        DF.icon('info-circle'),
        el('div', { text: 'These are the default settings, and readings have already started with them. Change anything and press Apply to keep your own.' })
      ]));
    }

    children.push(self.toolbar(), self.targetsSection(), self.actions());
    self.root.replaceChildren.apply(self.root, children);
  };

  SettingsView.prototype.toolbar = function () {
    return el('div', { className: 'df-toolbar' }, [
      el('span', { className: 'df-faint' }, ['Readings are kept in ', el('code', { text: this.dataDir }), '.']),
      el('a', { className: 'df-button', href: this.forecastUrl }, [DF.icon('area-chart'), 'View forecast'])
    ]);
  };

  SettingsView.prototype.targetsSection = function () {
    var self = this;
    var list = el('div', { className: 'df-targets' }, self.state.targets.map(function (target, index) {
      return self.targetEditor(target, index);
    }));
    if (!self.state.targets.length) {
      list.appendChild(el('div', { className: 'df-empty', text: 'Nothing is tracked. Add a target to start taking readings.' }));
    }

    return el('section', { className: 'df-section' }, [
      el('h3', { text: 'What to track' }),
      el('p', { text: 'Each target gets its own forecast, graph and optional warning.' }),
      list,
      el('div', { style: 'margin-top:12px' }, [
        el('button', {
          type: 'button',
          className: 'df-button',
          onclick: function () {
            self.state.targets.push({
              name: '',
              type: 'disks',
              members: [],
              intervalMinutes: 60,
              windowDays: 180,
              warnDays: 0
            });
            self.render();
            var names = self.root.querySelectorAll('.df-target input[type="text"]');
            if (names.length) {
              names[names.length - 1].focus();
            }
          }
        }, [DF.icon('plus'), 'Add target'])
      ])
    ]);
  };

  SettingsView.prototype.targetEditor = function (target, index) {
    var self = this;
    var prefix = 'df-t' + index + '-';
    var select = function (id, value, options, onchange) {
      return el('select', { id: id, onchange: function (event) { onchange(event.target.value); } }, options.map(function (option) {
        return el('option', { value: option[0], selected: String(option[0]) === String(value), disabled: option[2] || false, text: option[1] });
      }));
    };
    var field = function (id, label, control, hint) {
      return el('label', { className: 'df-field', for: id }, [el('span', { text: label }), control, hint ? el('small', { text: hint }) : null]);
    };

    var types = TYPES.filter(function (type) {
      return type[0] !== 'share' || self.inventory.shares.length || target.type === 'share';
    });

    var grid = el('div', { className: 'df-target-grid' }, [
      field(prefix + 'name', 'Name', el('input', {
        id: prefix + 'name',
        type: 'text',
        value: target.name,
        maxlength: 60,
        placeholder: 'For example: Media',
        oninput: function (event) { target.name = event.target.value; }
      })),
      field(prefix + 'type', 'What to track', select(prefix + 'type', target.type, types, function (value) {
        target.type = value;
        target.members = self.defaultMembers(value);
        self.render();
      })),
      field(prefix + 'interval', 'Take a reading every', select(prefix + 'interval', target.intervalMinutes, self.options.intervals.map(function (m) {
        return [m, DF.intervalLabel(m)];
      }), function (value) { target.intervalMinutes = parseInt(value, 10); })),
      field(prefix + 'window', 'Base the trend on', select(prefix + 'window', target.windowDays, self.options.windows.map(function (d) {
        return [d, d === 0 ? 'All history' : 'The last ' + DF.windowLabel(d)];
      }), function (value) { target.windowDays = parseInt(value, 10); }), 'Shorter reacts faster to change; longer is steadier.'),
      field(prefix + 'warn', 'Warn me when full within', select(prefix + 'warn', target.warnDays, self.options.warnings.map(function (d) {
        return [d, DF.warnLabel(d)];
      }), function (value) { target.warnDays = parseInt(value, 10); }), 'Sent through Unraid notifications.')
    ]);

    return el('div', { className: 'df-target' }, [
      grid,
      self.membersEditor(target, prefix),
      self.targetFoot(target, index)
    ]);
  };

  /** Readings already recorded for a saved target, or null when it has none. */
  SettingsView.prototype.recorded = function (target) {
    var history = target.id && this.history ? this.history[target.id] : null;
    return history && history.readings > 0 ? history : null;
  };

  /** Remove button, or the warning that asks before a target's readings are deleted. */
  SettingsView.prototype.targetFoot = function (target, index) {
    var self = this;
    var remove = function () {
      self.state.targets.splice(index, 1);
      self.render();
    };
    var recorded = self.recorded(target);

    if (target.confirmRemove && recorded) {
      return el('div', { className: 'df-target-foot df-confirm', role: 'alert' }, [
        el('span', {}, [
          DF.icon('exclamation-triangle'), ' ',
          el('strong', { text: 'Remove ' + (target.name || 'this target') + '? ' }),
          'Its ' + recorded.readings.toLocaleString() + ' readings since ' + DF.formatDate(recorded.firstReading) + ' will be deleted when you press Apply. This can\'t be undone.'
        ]),
        el('span', { className: 'df-actions' }, [
          el('button', {
            type: 'button',
            className: 'df-button',
            onclick: function () {
              delete target.confirmRemove;
              self.render();
            }
          }, ['Keep']),
          el('button', { type: 'button', className: 'df-button is-danger', onclick: remove }, [DF.icon('trash'), 'Remove and delete readings'])
        ])
      ]);
    }

    return el('div', { className: 'df-target-foot' }, [
      el('span', { className: 'df-faint', text: target.id ? '' : 'New: readings start after you press Apply.' }),
      el('button', {
        type: 'button',
        className: 'df-button is-quiet',
        onclick: function () {
          if (!recorded) {
            remove();
            return;
          }
          target.confirmRemove = true;
          self.render();
        }
      }, [DF.icon('trash'), 'Remove'])
    ]);
  };

  /** Saved targets that are no longer in the list, and whose readings Apply would delete. */
  SettingsView.prototype.pendingDeletes = function () {
    var self = this;
    var kept = self.state.targets.map(function (t) { return t.id; });
    return JSON.parse(self.original).targets.filter(function (t) {
      return kept.indexOf(t.id) === -1 && self.recorded(t);
    });
  };

  SettingsView.prototype.defaultMembers = function (type) {
    if (type === 'share') {
      return this.inventory.shares.length ? [this.inventory.shares[0].name] : [];
    }
    return [];
  };

  SettingsView.prototype.membersEditor = function (target, prefix) {
    var self = this;
    var sizeText = function (unit) {
      return unit.size ? DF.formatBytes(unit.size) : 'not mounted';
    };

    if (target.type === 'array') {
      var total = self.inventory.disks.reduce(function (sum, d) { return sum + (d.size || 0); }, 0);
      return el('div', { className: 'df-target-members df-faint', text: 'All ' + self.inventory.disks.length + ' data disks added together (' + DF.formatBytes(total) + '). Parity is not counted.' });
    }

    if (target.type === 'share') {
      var shareId = prefix + 'share';
      var current = self.inventory.shares.filter(function (s) { return s.name === target.members[0]; })[0];
      return el('div', { className: 'df-target-members' }, [
        el('label', { className: 'df-field', for: shareId, style: 'max-width:320px' }, [
          el('span', { text: 'Share' }),
          el('select', {
            id: shareId,
            onchange: function (event) {
              target.members = [event.target.value];
              self.render();
            }
          }, self.inventory.shares.map(function (share) {
            return el('option', { value: share.name, selected: target.members[0] === share.name, text: share.name });
          }))
        ]),
        current ? el('p', { className: 'df-faint', style: 'margin:6px 0 0', text: current.units.length ? 'Can use: ' + current.units.join(', ') + '.' : 'This share has no disks or pools it can use.' }) : null,
        el('p', { className: 'df-explain', text: 'This tracks the free space on the disks and pools the share can use. Other shares on those disks take from the same space, so it shows when the share will run out of room, not how big the share is.' })
      ]);
    }

    var units = self.inventory.disks.concat(self.inventory.pools);
    return el('div', { className: 'df-target-members' }, [
      el('div', { className: 'df-field' }, [el('span', { text: 'Disks and pools, added together' })]),
      el('div', { className: 'df-pills', role: 'group', 'aria-label': 'Disks and pools' }, units.map(function (unit) {
        return el('label', { className: 'df-pill' }, [
          el('input', {
            type: 'checkbox',
            checked: target.members.indexOf(unit.name) !== -1,
            onchange: function (event) {
              if (event.target.checked) {
                target.members.push(unit.name);
              } else {
                target.members = target.members.filter(function (m) { return m !== unit.name; });
              }
            }
          }),
          unit.name,
          el('small', { text: sizeText(unit) })
        ]);
      }))
    ]);
  };

  SettingsView.prototype.actions = function () {
    var self = this;
    var status = el('span', { className: 'df-status-text', 'aria-live': 'polite' });
    var apply = el('button', {
      type: 'button',
      className: 'df-button is-primary',
      onclick: function () {
        apply.disabled = true;
        status.textContent = 'Saving…';
        DF.postForm(self.api, 'save', { csrf_token: self.csrf, settings: JSON.stringify(self.state) }).then(function (data) {
          self.saved = true;
          self.state = JSON.parse(JSON.stringify(data.settings));
          self.original = JSON.stringify(data.settings);
          var lines = ['Saved. Changes to readings take effect at the next reading.'];
          if (data.deleted.length) {
            lines.push('Deleted the readings of ' + data.deleted.join(', ') + '.');
          }
          if (data.notDeleted.length) {
            lines.push('Could not delete the readings of ' + data.notDeleted.join(', ') + ' (is the array stopped?). They are still in ' + self.dataDir + '/history.');
          }
          self.render(el('div', { className: 'df-notice' + (data.notDeleted.length ? ' is-warning' : '') }, [
            DF.icon(data.notDeleted.length ? 'exclamation-triangle' : 'check'),
            el('div', {}, lines.map(function (line) { return el('div', { text: line }); }))
          ]));
        }).catch(function (error) {
          var errors = (error.body && error.body.errors) || [error.message];
          apply.disabled = false;
          status.textContent = '';
          self.render(el('div', { className: 'df-notice is-error', role: 'alert' }, [
            DF.icon('exclamation-circle'),
            el('div', {}, [el('strong', { text: 'Nothing was saved.' }), el('ul', {}, errors.map(function (e) { return el('li', { text: e }); }))])
          ]));
        });
      }
    }, ['Apply']);

    var pending = self.pendingDeletes();
    return el('div', { className: 'df-actions' }, [
      pending.length ? el('div', { className: 'df-notice is-warning', style: 'flex-basis:100%;margin:0' }, [
        DF.icon('exclamation-triangle'),
        el('div', { text: 'Apply will delete the readings of ' + pending.map(function (t) { return t.name; }).join(', ') + '. Press Reset to keep them.' })
      ]) : null,
      apply,
      el('button', {
        type: 'button',
        className: 'df-button',
        onclick: function () {
          self.state = JSON.parse(self.original);
          self.render();
        }
      }, ['Reset']),
      el('button', {
        type: 'button',
        className: 'df-button',
        onclick: function () { window.location.href = self.doneUrl; }
      }, ['Done']),
      status
    ]);
  };

  function start() {
    var root = document.getElementById('df-settings');
    if (root) {
      new SettingsView(root);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
