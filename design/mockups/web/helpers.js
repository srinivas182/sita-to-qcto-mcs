/* Helpers shared by roles.js (data) and render.js. */
(function () {
  // ---------- helpers used by roles.js ----------
  window.pill = function (t, tone) { return '<span class="pill ' + (tone || 'muted') + '">' + t + '</span>'; };
  window.initials = function (n) { return n.split(' ').map(function (p) { return p[0]; }).slice(0, 2).join(''); };
  window.who = function (n, s, dark) { return '<div class="who"><div class="av' + (dark ? ' dark' : '') + '">' + initials(n) + '</div><div><b>' + n + '</b>' + (s ? '<span>' + s + '</span>' : '') + '</div></div>'; };
  window.bar = function (p, tone) { return '<div class="bar ' + (tone || '') + '"><i style="width:' + p + '%"></i></div>'; };
  window.ring = function (p, tone) { return '<div class="ring ' + (tone || '') + '" style="--p:' + p + '" data-v="' + p + '"></div>'; };
  window.light = function (s) { var m = { g: ['Ready', 'ok'], a: ['Expiring', 'warn'], r: ['Blocked', 'bad'] }; return pill(m[s][0], m[s][1]); };
  window.btn = function (t, to, cls) { return '<a class="btn sm ' + (cls || '') + '" href="' + (to ? '#' + to : 'javascript:void(0)') + '" data-toast="' + (to ? '' : t) + '">' + t + '</a>'; };

})();
