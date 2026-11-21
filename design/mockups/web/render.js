/* Renderer for the web mock-ups: hub, app shell, and data-driven blocks. Data lives in roles.js. */
(function () {
  var I = window.icon;
  var ROLES = window.ROLES, GROUPS = window.ROLE_GROUPS;

  // ---------- block renderers ----------
  var R = {};
  R.kpis = function (b) {
    return '<div class="kpis">' + b.items.map(function (k) {
      return '<div class="kpi ' + (k.dark ? 'dark' : '') + '"><div class="k-top"><span>' + k.label + '</span><span class="k-ic">' + I(k.icon || 'chart') + '</span></div><div class="k-val mono">' + k.value + '</div>' + (k.delta ? '<div class="k-delta ' + (k.tone || '') + '">' + k.delta + '</div>' : '') + '</div>';
    }).join('') + '</div>';
  };
  function head(b) { return b.title ? '<div class="card-h"><h3>' + b.title + '</h3>' + (b.note ? '<small>' + b.note + '</small>' : '') + '</div>' : ''; }
  R.table = function (b) {
    return '<div class="card">' + head(b) + '<div class="table-wrap"><table class="t"><thead><tr>' + b.cols.map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>' + b.rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div></div>';
  };
  R.list = function (b) {
    return '<div class="card">' + head(b) + '<div class="list">' + b.items.map(function (i) {
      return '<div class="li"><div class="li-ic ' + (i.tone || '') + '">' + I(i.icon || 'file') + '</div><div class="li-body"><b>' + i.title + '</b>' + (i.sub ? '<span>' + i.sub + '</span>' : '') + '</div>' + (i.meta ? '<div class="li-meta">' + i.meta + '</div>' : '') + '</div>';
    }).join('') + '</div></div>';
  };
  R.steps = function (b) {
    return '<div class="card">' + head(b) + '<div class="steps">' + b.steps.map(function (s) {
      return '<div class="step ' + (s.state || '') + '"><div class="s-dot">' + (s.state === 'done' ? I('check') : '') + '</div><b>' + s.name + '</b><span>' + (s.sub || '') + '</span></div>';
    }).join('') + '</div></div>';
  };
  R.form = function (b) {
    return '<div class="card">' + head(b) + '<div class="form">' + b.fields.map(function (f) {
      var inner;
      if (f.type === 'drop') inner = '<div class="input drop">' + I('upload') + ' ' + f.value + '</div>';
      else if (f.type === 'toggle') inner = '<div class="input"><span style="flex:1">' + f.value + '</span><span class="toggle ' + (f.off ? 'off' : '') + '"></span></div>';
      else if (f.type === 'seg') inner = '<div class="seg">' + f.options.map(function (o, i) { return '<span class="' + (i === (f.on || 0) ? 'on' : '') + '">' + o + '</span>'; }).join('') + '</div>';
      else if (f.type === 'area') inner = '<div class="input" style="min-height:92px;align-items:flex-start">' + f.value + '</div>';
      else inner = '<div class="input ' + (f.ok ? 'ok' : '') + '">' + (f.icon ? I(f.icon) : '') + '<span style="flex:1">' + f.value + '</span>' + (f.ok ? I('check') : '') + (f.select ? '<span class="muted">▾</span>' : '') + '</div>';
      return '<div class="field ' + (f.full ? 'full' : '') + '"><label>' + f.label + '</label>' + inner + (f.hint ? '<span class="hint">' + f.hint + '</span>' : '') + '</div>';
    }).join('') + (b.submit ? '<div class="form-actions">' + (b.secondary ? '<a class="btn" href="javascript:void(0)" data-toast="' + b.secondary + '">' + b.secondary + '</a>' : '') + '<a class="btn primary" href="' + (b.to ? '#' + b.to : 'javascript:void(0)') + '" data-toast="' + (b.to ? '' : b.submit) + '">' + I('check') + b.submit + '</a></div>' : '') + '</div></div>';
  };
  R.bars = function (b) {
    var max = b.max || Math.max.apply(null, b.data.map(function (d) { return d[1]; }));
    return '<div class="card">' + head(b) + '<div class="bars">' + b.data.map(function (d) {
      return '<div class="brow"><span>' + d[0] + '</span>' + bar(Math.round(d[1] / max * 100), d[2]) + '<span class="v">' + (d[3] || d[1] + (b.unit || '')) + '</span></div>';
    }).join('') + '</div></div>';
  };
  R.callout = function (b) {
    return '<div class="callout ' + (b.tone || 'gold') + '"><div class="c-ic">' + I(b.icon || 'spark') + '</div><div><b>' + b.title + '</b><p>' + b.text + '</p></div>' + (b.action ? '<a class="btn ' + (b.tone === 'dark' ? 'gold' : 'primary') + '" href="' + (b.to ? '#' + b.to : 'javascript:void(0)') + '" data-toast="' + (b.to ? '' : b.action) + '">' + b.action + '</a>' : '') + '</div>';
  };
  R.tiles = function (b) {
    return '<div class="card">' + head(b) + '<div class="tiles">' + b.items.map(function (t) {
      return '<a class="tile ' + (t.feature ? 'feature' : '') + '" href="' + (t.to ? '#' + t.to : 'javascript:void(0)') + '"><div class="t-top"><span class="t-ic">' + I(t.icon || 'grid') + '</span>' + (t.badge || '') + '</div><b>' + t.title + '</b>' + (t.price ? '<div class="price">' + t.price + '</div>' : '') + '<p>' + (t.sub || '') + '</p>' + (t.meta ? '<div class="t-meta">' + t.meta + '</div>' : '') + '</a>';
    }).join('') + '</div></div>';
  };
  R.kanban = function (b) {
    return '<div class="card">' + head(b) + '<div class="kanban">' + b.columns.map(function (c) {
      return '<div class="col"><div class="col-h">' + c.name + '<span>' + c.cards.length + '</span></div>' + c.cards.map(function (k) {
        return '<div class="kcard"><b>' + k.title + '</b><span>' + (k.sub || '') + '</span>' + (k.tag || '') + '</div>';
      }).join('') + '</div>';
    }).join('') + '</div></div>';
  };
  R.timeline = function (b) {
    return '<div class="card">' + head(b) + '<div class="timeline">' + b.items.map(function (t) {
      return '<div class="tl ' + (t.now ? 'now' : '') + '"><small>' + t.time + '</small><b>' + t.title + '</b><span>' + (t.sub || '') + '</span></div>';
    }).join('') + '</div></div>';
  };
  R.chat = function (b) {
    return '<div class="card">' + head(b) + '<div class="chat">' + b.messages.map(function (m) {
      return '<div class="msg ' + m[0] + '">' + (m[0] === 'ai' ? '<b>' + I('spark') + ' AI assistant</b>' : '') + m[1] + '</div>';
    }).join('') + '<div class="chat-input"><div class="input">' + I('chat') + '<span class="muted">' + (b.placeholder || 'Ask anything…') + '</span></div><a class="btn primary" href="javascript:void(0)" data-toast="Message sent">' + I('send') + 'Send</a></div></div></div>';
  };
  R.evidence = function (b) {
    var tones = ['', 'b', 'c', 'd'];
    return '<div class="card">' + head(b) + '<div class="evidence">' + b.items.map(function (e, i) {
      return '<div class="ev"><div class="ph ' + tones[i % 4] + '">' + I(e.icon || 'camera') + '</div><div class="meta"><b>' + e.title + '</b><span>' + e.sub + '</span></div></div>';
    }).join('') + '</div></div>';
  };
  R.html = function (b) { return '<div class="card">' + head(b) + b.html + '</div>'; };

  function block(b) { return '<div class="span-' + (b.span || 12) + '">' + R[b.type](b) + '</div>'; }

  // ---------- pages ----------
  function roleCard(r) {
    return '<a class="role-card" href="#' + r.id + '-' + r.screens[0].id + '"><span class="role-ic">' + I(r.icon) + '</span><span><b>' + r.name + '</b><span>' + r.tag + '</span></span></a>';
  }
  function groups() {
    return GROUPS.map(function (g) {
      var rs = ROLES.filter(function (r) { return r.group === g; });
      return '<div class="hub-group"><h3>' + g + '</h3><div class="role-grid">' + rs.map(roleCard).join('') + '</div></div>';
    }).join('');
  }
  function brand(sub) {
    return '<div class="brand"><div class="brand-mark">S</div><div><div class="brand-name">Skills Ecosystem</div><div class="brand-sub">' + (sub || 'QCTO-ready SaaS') + '</div></div></div>';
  }
  function hub() {
    document.title = 'Skills Ecosystem — Web mock-ups';
    return '<div class="hub"><div class="hub-top">' + brand('TYM × MCS Joint Venture') + '<span class="hub-note">Sprint 0 · Clickable mock-up · Sample data only</span></div>' +
      '<h1>One platform for every step from <em>enrolment</em> to <em>employment</em>.</h1>' +
      '<p class="lead">Choose a role to walk through its journey. Every screen is clickable and uses sample data. ' + ROLES.length + ' roles, ' + ROLES.reduce(function (n, r) { return n + r.screens.length; }, 0) + ' screens.</p>' +
      groups() + '<div class="hub-foot"><span>Working title: QCTO Skills Ecosystem</span><a href="../mobile/index.html">Open the mobile app mock-ups →</a><a href="../index.html">Design overview</a></div></div>';
  }
  function app(role, screen) {
    document.title = screen.title + ' · ' + role.name + ' — Skills Ecosystem';
    var nav = role.screens.map(function (s) {
      return '<a class="' + (s.id === screen.id ? 'on' : '') + '" href="#' + role.id + '-' + s.id + '">' + I(s.icon || 'grid') + s.nav + '</a>';
    }).join('');
    var actions = (screen.actions || []).map(function (a) {
      return '<a class="btn ' + (a.primary ? 'primary' : a.gold ? 'gold' : '') + '" href="' + (a.to ? '#' + role.id + '-' + a.to : 'javascript:void(0)') + '" data-toast="' + (a.to ? '' : a.label) + '">' + (a.icon ? I(a.icon) : '') + a.label + '</a>';
    }).join('');
    return '<div class="app"><aside class="side">' + brand(role.context) +
      '<div class="tenant"><small>' + role.tenantLabel + '</small><b>' + role.tenant + '</b><span>' + I(role.tenantIcon || 'globe') + role.domain + '</span></div>' +
      '<div class="nav-label">' + role.name + '</div><nav class="nav">' + nav + '</nav>' +
      '<div class="side-foot"><div class="av dark">' + initials(role.persona) + '</div><div><b>' + role.persona + '</b><span>' + role.personaTitle + '</span></div></div></aside>' +
      '<div class="main"><header class="top"><div class="crumb">' + role.name + ' <span>/</span> <b>' + screen.nav + '</b></div>' +
      '<div class="search">' + I('search') + 'Search learners, packs, qualifications…<span class="kbd">⌘K</span></div>' +
      '<button class="icon-btn" data-toast="3 new notifications">' + I('bell') + '<span class="dotn"></span></button>' +
      '<button class="role-switch" id="switch">' + I('users') + 'Switch role</button></header>' +
      '<section class="view"><div class="page-head"><div><div class="eyebrow">' + (screen.eyebrow || role.name) + '</div><h1>' + screen.title + '</h1><p>' + (screen.sub || '') + '</p></div><div class="actions">' + actions + '</div></div>' +
      '<div class="blocks">' + screen.blocks.map(block).join('') + '</div>' +
      '<div class="note">' + I('eye') + 'Mock-up with sample data for review. Final design is refined in design workshops.</div></section></div></div>' +
      '<div class="modal" id="modal"><div class="modal-box"><button class="modal-close" id="close">' + I('x') + '</button><h2 style="font-family:var(--serif);font-weight:500;margin:0 0 6px">Switch role</h2><p style="color:rgba(255,255,255,.6);margin:0 0 8px">Walk through the journey of any role.</p>' + groups() + '</div></div>';
  }

  function render() {
    var h = (location.hash || '').replace('#', '');
    var root = document.getElementById('root');
    var role = null, screen = null;
    ROLES.forEach(function (r) { r.screens.forEach(function (s) { if (r.id + '-' + s.id === h) { role = r; screen = s; } }); });
    root.innerHTML = role ? app(role, screen) : hub();
    window.scrollTo(0, 0);
    var sw = document.getElementById('switch');
    if (sw) {
      var m = document.getElementById('modal');
      sw.onclick = function () { m.classList.add('show'); };
      document.getElementById('close').onclick = function () { m.classList.remove('show'); };
      m.onclick = function (e) { if (e.target === m) m.classList.remove('show'); };
    }
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-toast]');
    if (a && a.getAttribute('data-toast')) {
      e.preventDefault();
      var t = document.getElementById('toast');
      t.innerHTML = I('check') + a.getAttribute('data-toast') + ' — mock-up action';
      t.classList.add('show');
      clearTimeout(window._tt); window._tt = setTimeout(function () { t.classList.remove('show'); }, 2200);
    }
  });
  window.addEventListener('hashchange', render);
  render();
})();
