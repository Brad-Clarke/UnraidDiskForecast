/* Disk Forecast: the settings view (readings folder, shares switch, targets). */
(function () {
  'use strict';

  var DF = window.DiskForecast;
  var el = DF.el;
  var TYPES = [
    ['array', 'Whole array'],
    ['pool', 'A pool'],
    ['disks', 'Chosen disks or pools'],
    ['share', 'A share']
  ];

  function SettingsView(root) {
    this.root = root;
    this.api = root.getAttribute('data-api');
    this.csrf = root.getAttribute('data-csrf') || window.csrf_token || '';
    this.doneUrl = root.getAttribute('data-done-url');
    this.load();
  }

  SettingsView.prototype.load = function () {
    var self = this;
    self.root.replaceChildren(el('div', { className: 'df-empty', text: 'Loading settings…' }));
    DF.getJson(self.api, { action: 'settings' }).then(function (data) {
      self.inventory = data.inventory;
      self.options = data.options;
      self.saved = data.saved;
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

    children.push(self.readingsSection(), self.sharesSection(), self.targetsSection(), self.actions());
    self.root.replaceChildren.apply(self.root, children);
  };

  SettingsView.prototype.readingsSection = function () {
    var self = this;
    var id = 'df-data-dir';
    return el('section', { className: 'df-section' }, [
      el('h3', { text: 'Readings' }),
      el('p', { text: 'Each reading is one short line added to a file in this folder.' }),
      el('label', { className: 'df-field', for: id }, [
        el('span', { text: 'Readings folder' }),
        el('input', {
          id: id,
          type: 'text',
          value: self.state.dataDir,
          spellcheck: 'false',
          oninput: function (event) { self.state.dataDir = event.target.value; }
        }),
        el('small', { text: 'Use a pool path such as /mnt/cache/appdata/diskforecast. A folder under /mnt/user or on an array disk can wake sleeping disks at every reading. Existing readings are not moved if you change this.' })
      ])
    ]);
  };

  SettingsView.prototype.sharesSection = function () {
    var self = this;
    return el('section', { className: 'df-section' }, [
      el('h3', { text: 'Shares' }),
      el('label', { className: 'df-check' }, [
        el('input', {
          type: 'checkbox',
          checked: self.state.sharesEnabled,
          onchange: function (event) {
            self.state.sharesEnabled = event.target.checked;
            self.render();
          }
        }),
        'Allow share targets'
      ]),
      el('div', { className: 'df-explain' }, [
        el('strong', { text: 'How share targets work. ' }),
        'A share\'s forecast follows the free space on the disks and pools the share is allowed to use. ',
        'Other shares on the same disks fill that space too, so the forecast answers "when will this share run out of room?", not "how much is this share growing?". ',
        'Nothing reads your folders, so no disks are woken up.'
      ])
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
              type: self.inventory.pools.length ? 'pool' : 'array',
              members: self.inventory.pools.length ? [self.inventory.pools[0].name] : [],
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

    var types = TYPES.map(function (type) {
      var disabled = (type[0] === 'share' && !self.state.sharesEnabled) || (type[0] === 'pool' && !self.inventory.pools.length);
      return [type[0], type[1] + (type[0] === 'share' && !self.state.sharesEnabled ? ' (turned off)' : ''), disabled && target.type !== type[0]];
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
      el('div', { className: 'df-target-foot' }, [
        el('span', { className: 'df-faint', text: target.id ? '' : 'New: readings start after you press Apply.' }),
        el('button', {
          type: 'button',
          className: 'df-button is-quiet',
          onclick: function () {
            self.state.targets.splice(index, 1);
            self.render();
          }
        }, [DF.icon('trash'), 'Remove'])
      ])
    ]);
  };

  SettingsView.prototype.defaultMembers = function (type) {
    if (type === 'pool') {
      return this.inventory.pools.length ? [this.inventory.pools[0].name] : [];
    }
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

    if (target.type === 'pool') {
      var poolId = prefix + 'pool';
      return el('div', { className: 'df-target-members' }, [
        el('label', { className: 'df-field', for: poolId, style: 'max-width:320px' }, [
          el('span', { text: 'Pool' }),
          el('select', { id: poolId, onchange: function (event) { target.members = [event.target.value]; } }, self.inventory.pools.map(function (pool) {
            return el('option', { value: pool.name, selected: target.members[0] === pool.name, text: pool.name + ' (' + sizeText(pool) + ')' });
          }))
        ])
      ]);
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
        current ? el('p', { className: 'df-faint', style: 'margin:6px 0 0', text: current.units.length ? 'Can use: ' + current.units.join(', ') + '.' : 'This share has no disks or pools it can use.' }) : null
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
          self.render(el('div', { className: 'df-notice' }, [DF.icon('check'), el('div', { text: 'Saved. Changes to readings take effect at the next reading.' })]));
          document.dispatchEvent(new CustomEvent('diskforecast:saved'));
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

    return el('div', { className: 'df-actions' }, [
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
