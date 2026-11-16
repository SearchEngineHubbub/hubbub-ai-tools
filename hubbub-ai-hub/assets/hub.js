(function () {
  'use strict';
  var API = (window.HubbubAIHub && window.HubbubAIHub.api) || '/wp-json/hubbub/v1';
  function api(path, qs) { return API + path + (API.indexOf('?') === -1 ? '?' : '&') + qs; }

  var LABEL = { pass: 'Pass', warn: 'Improve', fail: 'Fix', info: 'Note' };
  var GROUPS = [
    ['access', 'Reach', 'Can AI reach it?'],
    ['guidance', 'Guidance', 'Does it guide AI tools?'],
    ['content', 'Readability', 'Can AI read it?'],
    ['structure', 'Structure', 'Can AI understand it?'],
    ['trust', 'Trust', 'Can AI trust it?']
  ];
  var ICON = {
    pass: '<path d="m5 12 5 5L20 7"/>',
    warn: '<path d="M12 8v5m0 4h.01"/>',
    fail: '<path d="M7 7l10 10M17 7 7 17"/>',
    info: '<path d="M12 11v6m0-10h.01"/>'
  };

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function svg(paths, cls) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('class', cls || 'hb-ico');
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = paths;
    return s;
  }
  function tone(score) { return score >= 85 ? 'good' : score >= 60 ? 'mid' : 'bad'; }

  function gauge(score) {
    var wrap = el('div', 'hb-gauge hb-gauge--' + tone(score));
    var r = 52, c = 2 * Math.PI * r;
    wrap.innerHTML = '<svg viewBox="0 0 120 120" aria-hidden="true"><circle class="hb-gauge__bg" cx="60" cy="60" r="' + r + '"/>' +
      '<circle class="hb-gauge__fg" cx="60" cy="60" r="' + r + '" stroke-dasharray="' + c + '" stroke-dashoffset="' + c + '"/></svg>';
    var num = el('div', 'hb-gauge__num');
    num.appendChild(el('strong', null, '0'));
    num.appendChild(el('span', null, '/100'));
    wrap.appendChild(num);
    requestAnimationFrame(function () {
      wrap.querySelector('.hb-gauge__fg').style.strokeDashoffset = String(c * (1 - score / 100));
      var start = null, strong = num.querySelector('strong');
      function step(t) {
        if (!start) start = t;
        var p = Math.min(1, (t - start) / 900);
        strong.textContent = String(Math.round(score * (1 - Math.pow(1 - p, 3))));
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
    return wrap;
  }

  function groupScore(items) {
    var w = { pass: 1, warn: 0.5, fail: 0 }, n = 0, got = 0;
    items.forEach(function (c) { if (c.status !== 'info') { n++; got += w[c.status]; } });
    return n ? Math.round(100 * got / n) : null;
  }

  function renderReport(out, data) {
    out.innerHTML = '';
    var card = el('section', 'hb-report');

    var top = el('div', 'hb-report__top');
    top.appendChild(gauge(data.score));
    var meta = el('div', 'hb-report__meta');
    meta.appendChild(el('span', 'hb-grade hb-grade--' + tone(data.score), data.grade));
    meta.appendChild(el('p', 'hb-report__url', data.url));
    var counts = { pass: 0, warn: 0, fail: 0 };
    data.checks.forEach(function (c) { if (counts[c.status] != null) counts[c.status]++; });
    var sum = el('ul', 'hb-tally');
    [['fail', 'to fix'], ['warn', 'to improve'], ['pass', 'passed']].forEach(function (k) {
      var li = el('li', 'hb-tally--' + k[0]);
      li.appendChild(el('strong', null, String(counts[k[0]])));
      li.appendChild(document.createTextNode(' ' + k[1]));
      sum.appendChild(li);
    });
    meta.appendChild(sum);
    top.appendChild(meta);

    var bars = el('div', 'hb-bars');
    GROUPS.forEach(function (g) {
      var s = groupScore(data.checks.filter(function (c) { return c.group === g[0]; }));
      if (s === null) return;
      var row = el('div', 'hb-bar');
      row.appendChild(el('span', 'hb-bar__label', g[1]));
      var track = el('span', 'hb-bar__track');
      var fill = el('span', 'hb-bar__fill hb-bar__fill--' + tone(s));
      track.appendChild(fill);
      row.appendChild(track);
      row.appendChild(el('span', 'hb-bar__val', s + '%'));
      bars.appendChild(row);
      requestAnimationFrame(function () { fill.style.width = s + '%'; });
    });
    top.appendChild(bars);
    card.appendChild(top);

    var order = { fail: 0, warn: 1, pass: 2, info: 3 };
    GROUPS.forEach(function (g) {
      var items = data.checks.filter(function (c) { return c.group === g[0]; })
        .sort(function (a, b) { return order[a.status] - order[b.status]; });
      if (!items.length) return;
      var sec = el('div', 'hb-group');
      sec.appendChild(el('h3', 'hb-group__title', g[2]));
      var grid = el('div', 'hb-checks');
      items.forEach(function (c) {
        var it = el('div', 'hb-check hb-check--' + c.status);
        var ic = el('span', 'hb-check__icon');
        ic.appendChild(svg(ICON[c.status]));
        it.appendChild(ic);
        var body = el('div', 'hb-check__body');
        var h = el('div', 'hb-check__head');
        h.appendChild(el('strong', null, c.label));
        h.appendChild(el('span', 'hb-check__badge', LABEL[c.status]));
        body.appendChild(h);
        body.appendChild(el('p', null, c.detail));
        if (c.fix) body.appendChild(el('p', 'hb-check__fix', c.fix));
        it.appendChild(body);
        grid.appendChild(it);
      });
      sec.appendChild(grid);
      card.appendChild(sec);
    });

    if (data.crawlers && data.crawlers.length) {
      var det = el('details', 'hb-botlist');
      var blocked = data.crawlers.filter(function (b) { return !b.allowed; }).length;
      det.appendChild(el('summary', null, 'Crawler by crawler: ' + (data.crawlers.length - blocked) + ' allowed, ' + blocked + ' blocked'));
      var list = el('div', 'hb-botlist__grid');
      data.crawlers.forEach(function (b) {
        var row = el('div', 'hb-botrow ' + (b.allowed ? 'is-ok' : 'is-blocked'));
        row.appendChild(el('span', 'hb-dot'));
        row.appendChild(el('code', null, b.name));
        row.appendChild(el('span', 'hb-botrow__co', b.company + ' · ' + b.purpose));
        row.appendChild(el('span', 'hb-botrow__state', b.allowed ? 'Allowed' : 'Blocked'));
        list.appendChild(row);
      });
      det.appendChild(list);
      card.appendChild(det);
    }

    var share = el('div', 'hb-sharebox');
    share.appendChild(el('span', null, 'Share this report'));
    var input = el('input');
    input.readOnly = true;
    input.value = data.report;
    share.appendChild(input);
    var btn = el('button', 'hb-btn hb-btn--ghost', 'Copy link');
    btn.type = 'button';
    btn.addEventListener('click', function () {
      if (navigator.clipboard) navigator.clipboard.writeText(data.report).then(function () { btn.textContent = 'Copied'; });
    });
    share.appendChild(btn);
    card.appendChild(share);
    out.appendChild(card);
  }

  function loading(out) {
    out.innerHTML = '';
    var box = el('section', 'hb-report hb-loading');
    var steps = ['Fetching the page as an AI crawler', 'Reading robots.txt for every AI bot', 'Looking for llms.txt', 'Checking structure, schema and trust signals'];
    var ul = el('ul', 'hb-steps');
    steps.forEach(function (s, i) {
      var li = el('li', null, s);
      li.style.animationDelay = (i * 0.45) + 's';
      ul.appendChild(li);
    });
    box.appendChild(el('div', 'hb-spinner'));
    box.appendChild(ul);
    out.appendChild(box);
  }

  function initChecker(root) {
    var form = root.querySelector('form');
    var input = root.querySelector('input[name=url]');
    var btn = form.querySelector('button');
    var out = root.querySelector('.hubbub-checker__out');
    function run() {
      var url = input.value.trim();
      if (!url) { input.focus(); return; }
      btn.disabled = true;
      loading(out);
      fetch(api('/check', 'url=' + encodeURIComponent(url)))
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok) { out.innerHTML = ''; out.appendChild(el('p', 'hb-error', res.j.message || 'Something went wrong.')); return; }
          renderReport(out, res.j);
          if (out.scrollIntoView) out.scrollIntoView({ behavior: 'smooth', block: 'start' });
          if (window.history && history.replaceState) history.replaceState(null, '', location.pathname + location.search.replace(/([?&])url=[^&]*&?/, '$1').replace(/[?&]$/, '') + (location.search.replace(/([?&])url=[^&]*&?/, '$1').replace(/[?&]$/, '') ? '&' : '?') + 'url=' + encodeURIComponent(url));
        })
        .catch(function () { out.innerHTML = ''; out.appendChild(el('p', 'hb-error', 'Could not run the check. Please try again.')); })
        .then(function () { btn.disabled = false; });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
    Array.prototype.forEach.call(root.querySelectorAll('.hb-example'), function (b) {
      b.addEventListener('click', function () { input.value = b.getAttribute('data-url'); run(); });
    });
    if (root.getAttribute('data-prefill')) run();
  }

  function initCrawlers(root) {
    var q = root.querySelector('.hubbub-crawlers__q');
    var company = root.querySelector('.hubbub-crawlers__company');
    var segs = Array.prototype.slice.call(root.querySelectorAll('.hb-seg button'));
    var stats = Array.prototype.slice.call(root.querySelectorAll('.hb-stat'));
    var cards = Array.prototype.slice.call(root.querySelectorAll('.hb-bot'));
    var count = root.querySelector('.hb-count');
    var purpose = '';
    function setPurpose(p) {
      purpose = p;
      segs.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-p') === p); });
      stats.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-filter') === p); });
      filter();
    }
    function filter() {
      var t = q.value.toLowerCase(), c = company.value, n = 0;
      cards.forEach(function (r) {
        var show = (!purpose || r.getAttribute('data-purpose') === purpose) &&
          (!c || r.getAttribute('data-company') === c) &&
          (!t || r.textContent.toLowerCase().indexOf(t) !== -1);
        r.hidden = !show;
        if (show) n++;
      });
      count.textContent = n === cards.length ? cards.length + ' crawlers' : 'Showing ' + n + ' of ' + cards.length + ' crawlers';
    }
    segs.forEach(function (b) { b.addEventListener('click', function () { setPurpose(b.getAttribute('data-p')); }); });
    stats.forEach(function (b) { b.addEventListener('click', function () { setPurpose(b.getAttribute('data-filter')); }); });
    q.addEventListener('input', filter);
    company.addEventListener('change', filter);
    filter();

    var boxes = Array.prototype.slice.call(root.querySelectorAll('.hb-toggle input'));
    var pre = root.querySelector('.hubbub-builder__out');
    var copy = root.querySelector('.hubbub-builder__copy');
    function build() {
      var block = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
      fetch(api('/robots', 'block=' + block.join(',')))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          pre.innerHTML = '';
          j.robots_txt.split('\n').forEach(function (line) {
            var span = el('span', /^#/.test(line) ? 'c' : /^Disallow/.test(line) ? 'd' : /^Allow/.test(line) ? 'a' : /^User-agent/.test(line) ? 'u' : null, line);
            pre.appendChild(span);
            pre.appendChild(document.createTextNode('\n'));
          });
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', build); });
    copy.addEventListener('click', function () {
      if (navigator.clipboard) navigator.clipboard.writeText(pre.textContent).then(function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy'; }, 1500); });
    });
    build();
  }

  function initAds(root) {
    var segs = Array.prototype.slice.call(root.querySelectorAll('.hb-seg button'));
    var stats = Array.prototype.slice.call(root.querySelectorAll('.hb-stat'));
    var cards = Array.prototype.slice.call(root.querySelectorAll('.hb-ad'));
    var count = root.querySelector('.hb-count');
    function set(g) {
      segs.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-p') === g); });
      stats.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-filter') === g); });
      var n = 0;
      cards.forEach(function (c) { var show = !g || c.getAttribute('data-group') === g; c.hidden = !show; if (show) n++; });
      count.textContent = n === cards.length ? cards.length + ' products' : 'Showing ' + n + ' of ' + cards.length + ' products';
    }
    segs.forEach(function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-p')); }); });
    stats.forEach(function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-filter')); }); });
    set('');
  }

  function initCitations(root) {
    var segs = Array.prototype.slice.call(root.querySelectorAll('.hb-seg button'));
    var rows = Array.prototype.slice.call(root.querySelectorAll('.hb-site'));
    var count = root.querySelector('.hb-count');
    function set(k) {
      segs.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-p') === k); });
      var n = 0;
      rows.forEach(function (r) { var show = !k || r.getAttribute('data-kind') === k; r.hidden = !show; if (show) n++; });
      count.textContent = n === rows.length ? rows.length + ' sites' : 'Showing ' + n + ' of ' + rows.length + ' sites';
    }
    segs.forEach(function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-p')); }); });
    set('');
  }

  // Grow meters and bars when they scroll into view.
  // Line charts: a readout follows the pointer to the nearest point.
  function initLines(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.hb-line[data-line]'), function (fig) {
      var pts; try { pts = JSON.parse(fig.getAttribute('data-line')); } catch (e) { return; }
      var plot = fig.querySelector('.hb-line__plot'), tip = fig.querySelector('.hb-line__tip'), guide = fig.querySelector('.hb-line__guide');
      if (!plot || !tip || !pts.length) return;
      function show(clientX) {
        var r = plot.getBoundingClientRect(), f = (clientX - r.left) / r.width * 100, best = 0;
        pts.forEach(function (p, i) { if (Math.abs(p[2] - f) < Math.abs(pts[best][2] - f)) best = i; });
        var p = pts[best];
        tip.innerHTML = '<b></b><span></span>';
        tip.firstChild.textContent = p[1]; tip.lastChild.textContent = p[0];
        tip.style.left = p[2] + '%'; tip.style.top = p[3] + '%';
        tip.classList.toggle('is-right', p[2] > 70); tip.classList.toggle('is-left', p[2] < 30);
        guide.style.left = p[2] + '%';
        tip.hidden = false; guide.hidden = false;
      }
      function hide() { tip.hidden = true; guide.hidden = true; }
      plot.addEventListener('pointermove', function (e) { show(e.clientX); });
      plot.addEventListener('pointerdown', function (e) { show(e.clientX); });
      plot.addEventListener('pointerleave', hide);
    });
  }

  function initReveal(root) {
    var els = root.querySelectorAll('.hb-share, .hb-mix, .hb-table--sites, .hb-tiles, .hb-bigfigs, .hb-line');
    Array.prototype.forEach.call(els, function (el) {
      if (!('IntersectionObserver' in window)) { el.classList.add('is-seen'); return; }
      var io = new IntersectionObserver(function (en) {
        if (en[0].isIntersecting) { el.classList.add('is-seen'); io.disconnect(); }
      }, { threshold: 0.15 });
      io.observe(el);
    });
  }

  function initCommon(root) {
    // Copy buttons.
    Array.prototype.forEach.call(root.querySelectorAll('[data-copy]'), function (b) {
      b.addEventListener('click', function () {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
          var label = b.querySelector('span') || b, old = label.textContent;
          b.classList.add('is-done');
          label.textContent = 'Copied';
          setTimeout(function () { b.classList.remove('is-done'); label.textContent = old; }, 1600);
        });
      });
    });
    // Tabs.
    Array.prototype.forEach.call(root.querySelectorAll('.hb-tabs'), function (list) {
      var tabs = Array.prototype.slice.call(list.querySelectorAll('[role=tab]'));
      function show(t) {
        tabs.forEach(function (x) {
          var on = x === t;
          x.classList.toggle('is-on', on);
          x.setAttribute('aria-selected', on ? 'true' : 'false');
          x.tabIndex = on ? 0 : -1;
          var pan = document.getElementById(x.getAttribute('aria-controls'));
          if (pan) pan.hidden = !on;
        });
      }
      tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(t); });
        t.addEventListener('keydown', function (e) {
          var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
          if (j === null) return;
          var n = tabs[(j + tabs.length) % tabs.length];
          show(n); n.focus();
        });
      });
    });
    // Cursor spotlight on cards.
    root.addEventListener('pointermove', function (e) {
      var c = e.target.closest && e.target.closest('.hb-card');
      if (!c) return;
      var r = c.getBoundingClientRect();
      c.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      c.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
    // Count up stats when they scroll into view.
    var nums = root.querySelectorAll('.hb-stat__num[data-count]');
    if (!('IntersectionObserver' in window) || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        var end = +en.target.getAttribute('data-count'), start = null;
        function step(t) {
          if (!start) start = t;
          var p = Math.min(1, (t - start) / 900);
          en.target.textContent = String(Math.round(end * (1 - Math.pow(1 - p, 3))));
          if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
      });
    });
    Array.prototype.forEach.call(nums, function (n) { io.observe(n); });
  }

  // Knowledge graph on the Tools page. Decorative: the same links are in the cards below.
  function initGraph(fig) {
    var data;
    try { data = JSON.parse(fig.getAttribute('data-graph')); } catch (e) { return; }
    var stage = fig.querySelector('.hb-graph__stage'), cap = fig.querySelector('.hb-graph__cap');
    fig.classList.add('is-ready');
    var NS = 'http://www.w3.org/2000/svg';
    var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Phones get a taller layout with bigger type, and topic names only for the active tool.
    var compact = stage.clientWidth < 700;
    // A knowledge-graph layout: small round nodes, labels beside them, hairline edges.
    var G = compact
      ? { W: 700, H: 820, tx: 215, ty: 235, px: 318, py: 360, fan: 15, hub: 58, tr: 14, dr: 7, fs: 30, ts: 24 }
      : { W: 1140, H: 600, tx: 300, ty: 165, px: 470, py: 262, fan: 14, hub: 46, tr: 9, dr: 4.5, fs: 17, ts: 13 };
    var W = G.W, H = G.H, CX = W / 2, CY = H / 2 + (compact ? 10 : 6);
    function s(tag, attrs, parent) {
      var n = document.createElementNS(NS, tag);
      for (var k in attrs) n.setAttribute(k, attrs[k]);
      if (parent) parent.appendChild(n);
      return n;
    }
    var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'hb-graph__svg' + (compact ? ' is-compact' : ''), focusable: 'false' }, stage);
    var gRings = s('g', { class: 'hb-g-rings' }, svg);
    [0, 1].forEach(function (i) { s('circle', { cx: CX, cy: CY, r: G.hub, style: 'animation-delay:' + (i * 2.4) + 's' }, gRings); });
    var gEdges = s('g', {}, svg), gPulse = s('g', {}, svg), gNodes = s('g', {}, svg);
    var nodes = [], edges = [], byLabel = {};

    function node(o) {
      o.px = 0; o.py = 0; o.ph = Math.random() * 6.28; o.sp = 0.25 + Math.random() * 0.25;
      o.x = o.hx; o.y = o.hy;
      nodes.push(o);
      return o;
    }
    // A label beside a node, pushed outward from the centre.
    function label(g, text, c, si, gap, cls, lim) {
      lim = lim === undefined ? 0.35 : lim;
      var anchor = c > lim ? 'start' : c < -lim ? 'end' : 'middle';
      var x = anchor === 'start' ? gap : anchor === 'end' ? -gap : 0;
      var y = anchor === 'middle' ? (si < 0 ? -gap * 1.1 : gap * 1.1) : 0;
      var t = s('text', { x: x, y: y, dy: anchor === 'middle' ? (si < 0 ? '0' : '.7em') : '.35em', 'text-anchor': anchor, class: cls }, g);
      t.textContent = text;
      return { el: t, anchor: anchor };
    }
    var sizers = [];
    function edge(a, b, cls, bend) {
      var e = { a: a, b: b, bend: bend || 0.06, el: s('path', { class: 'hb-g-edge ' + (cls || '') }, gEdges) };
      edges.push(e);
      return e;
    }

    var hub = node({ kind: 'hub', hx: CX, hy: CY, amp: 0 });
    hub.el = s('g', { class: 'hb-g-hub' }, gNodes);
    s('circle', { r: G.hub }, hub.el);
    s('text', { y: -G.fs * 0.55, 'text-anchor': 'middle', class: 'hb-g-hub__k' }, hub.el).textContent = 'Hubbub';
    s('text', { y: G.fs * 0.55, 'text-anchor': 'middle', class: 'hb-g-hub__t' }, hub.el).textContent = 'Knowledge';
    s('text', { y: G.fs * 1.55, 'text-anchor': 'middle', class: 'hb-g-hub__t' }, hub.el).textContent = 'Hub';

    var count = data.tools.length;
    data.tools.forEach(function (t, i) {
      // The first tool (AI Search Insights) sits at the top; the rest spread evenly around.
      var a = (-90 + i * 360 / count) * Math.PI / 180, c = Math.cos(a), si = Math.sin(a);
      var tool = node({ kind: 'tool', data: t, hx: CX + c * G.tx, hy: CY + si * G.ty, amp: 2, topics: [] });
      tool.el = s('g', { class: 'hb-g-tool' + (i === 0 ? ' is-lead' : '') + (t.soon ? ' is-soon' : '') }, gNodes);
      s('circle', { r: G.tr * 2.6, class: 'hb-g-hit' }, tool.el);
      s('circle', { r: G.tr * 1.9, class: 'hb-g-tool__halo' }, tool.el);
      s('circle', { r: G.tr, class: 'hb-g-tool__dot' }, tool.el);
      // Tool names sit on the outside of their node, except at the top and bottom where they sit beside it.
      // On phones, names of the side tools sit under their node so they stay inside the frame.
      if (compact && i > 0) label(tool.el, t.mini || t.label, 0, 1, G.tr + 12, 'hb-g-tool__tx');
      else label(tool.el, compact && t.mini ? t.mini : t.label, Math.abs(c) < 0.35 ? 1 : c, si, G.tr + (compact ? 12 : 8), 'hb-g-tool__tx');
      tool.edge = edge(hub, tool, 'is-spoke' + (t.soon ? ' is-soon' : ''), 0.04);
      var n = t.topics.length;
      t.topics.forEach(function (lab, j) {
        var b = a + ((j - (n - 1) / 2) * G.fan) * Math.PI / 180, bc = Math.cos(b), bs = Math.sin(b);
        var tp = node({ kind: 'topic', hx: CX + bc * G.px, hy: CY + bs * G.py, amp: 3, tool: tool });
        tp.bx = tp.hx;
        tp.el = s('g', { class: 'hb-g-topic' }, gNodes);
        s('circle', { r: G.dr * 3, class: 'hb-g-hit' }, tp.el);
        s('circle', { r: G.dr, class: 'hb-g-topic__dot' }, tp.el);
        var L = label(tp.el, lab, bc, bs, G.dr + (compact ? 10 : 7), 'hb-g-topic__tx', 0.1);
        // Keep each label inside the frame once its width is known.
        sizers.push(function () {
          var w = L.el.getComputedTextLength() + G.dr + 10;
          if (L.anchor === 'start') tp.hx = Math.min(tp.bx, W - w - 6);
          else if (L.anchor === 'end') tp.hx = Math.max(tp.bx, w + 6);
          else tp.hx = Math.max(w / 2 + 6, Math.min(W - w / 2 - 6, tp.bx));
        });
        tp.edge = edge(tool, tp, 'is-leaf', 0.05);
        tool.topics.push(tp);
        byLabel[lab] = tp;
      });
    });
    (data.links || []).forEach(function (l) {
      if (byLabel[l[0]] && byLabel[l[1]]) edge(byLabel[l[0]], byLabel[l[1]], 'is-cross', 0.1);
    });
    var tools = nodes.filter(function (n) { return n.kind === 'tool'; });
    function size() { sizers.forEach(function (f) { f(); }); }
    size();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(size);

    // Caption: the active tool, or a hint.
    var hintText = compact ? 'Tap a tool to explore what it covers' : 'Hover a tool to explore what it covers';
    var active = null, touched = false;
    function setHint() { cap.innerHTML = ''; cap.appendChild(el('span', 'hb-graph__hint', hintText)); }
    setHint();
    function setActive(tool) {
      if (tool === active) return;
      active = tool;
      svg.classList.toggle('has-active', !!tool);
      nodes.forEach(function (n) { n.el.classList.toggle('is-on', !!tool && (n === tool || n.tool === tool || n === hub)); });
      edges.forEach(function (e) {
        e.el.classList.toggle('is-on', !!tool && (e.b === tool || e.a === tool));
        e.el.classList.toggle('is-near', !!tool && e.a !== tool && e.b !== tool && (e.a.tool === tool || e.b.tool === tool));
      });
      if (!tool) { setHint(); return; }
      var t = tool.data;
      cap.innerHTML = '';
      cap.appendChild(el('span', 'hb-label', t.meta));
      cap.appendChild(el('strong', 'hb-graph__title', t.label));
      cap.appendChild(el('p', 'hb-graph__desc', t.desc));
      if (compact) {
        var tags = el('p', 'hb-graph__tags');
        t.topics.forEach(function (x) { tags.appendChild(el('span', 'hb-tag', x)); });
        cap.appendChild(tags);
      }
      if (t.url) {
        var a = el('a', 'hb-graph__go', 'Open the ' + t.label);
        a.href = t.url; a.tabIndex = -1;
        a.insertAdjacentHTML('beforeend', ' <span aria-hidden="true">→</span>');
        cap.appendChild(a);
      }
      cap.classList.remove('is-in'); void cap.offsetWidth; cap.classList.add('is-in');
    }
    function nodeFor(target) {
      var g = target.closest && target.closest('.hb-g-tool, .hb-g-topic');
      if (!g) return null;
      for (var i = 0; i < nodes.length; i++) if (nodes[i].el === g) return nodes[i];
      return null;
    }
    var ptr = null;
    function toLocal(e) {
      var r = svg.getBoundingClientRect();
      return { x: (e.clientX - r.left) * W / r.width, y: (e.clientY - r.top) * H / r.height };
    }
    svg.addEventListener('pointermove', function (e) {
      ptr = toLocal(e);
      if (e.pointerType !== 'mouse') return;
      touched = true;
      var n = nodeFor(e.target);
      if (n) setActive(n.kind === 'tool' ? n : n.tool);
    });
    svg.addEventListener('pointerleave', function () { ptr = null; });
    svg.addEventListener('click', function (e) {
      touched = true;
      var n = nodeFor(e.target);
      if (!n) return;
      var tool = n.kind === 'tool' ? n : n.tool;
      if (tool === active && n.kind === 'tool' && tool.data.url) { window.location.href = tool.data.url; return; }
      setActive(tool);
    });

    function ctrl(e) {
      var mx = (e.a.x + e.b.x) / 2, my = (e.a.y + e.b.y) / 2, dx = e.b.x - e.a.x, dy = e.b.y - e.a.y;
      return { x: mx - dy * e.bend, y: my + dx * e.bend };
    }
    function qpt(a, c, b, f) {
      var u = 1 - f;
      return { x: u * u * a.x + 2 * u * f * c.x + f * f * b.x, y: u * u * a.y + 2 * u * f * c.y + f * f * b.y };
    }

    var t0 = null, running = false, visible = true, lastCycle = 0, cycle = -1, pulses = [], lastPulse = 0;
    function place(time) {
      var age = t0 === null || still ? 1 : Math.min(1, (time - t0) / 1300);
      nodes.forEach(function (n, idx) {
        var d = n.kind === 'hub' ? 0 : n.kind === 'tool' ? 0.1 : 0.3 + (idx % 6) * 0.04;
        var k = still ? 1 : Math.max(0, Math.min(1, (age - d) / (1 - d)));
        k = 1 - Math.pow(1 - k, 3);
        var wob = still ? 0 : n.amp;
        var tx = Math.sin(time / 1000 * n.sp + n.ph) * wob, ty = Math.cos(time / 1000 * n.sp * 0.8 + n.ph) * wob;
        var px = 0, py = 0;
        if (ptr && !still && n.kind !== 'hub') {
          var vx = n.hx - ptr.x, vy = n.hy - ptr.y, dist = Math.sqrt(vx * vx + vy * vy) || 1;
          if (dist < 120) { var f = (1 - dist / 120) * 10; px = vx / dist * f; py = vy / dist * f; }
        }
        n.px += (px - n.px) * 0.1; n.py += (py - n.py) * 0.1;
        n.x = CX + (n.hx - CX) * k + tx + n.px;
        n.y = CY + (n.hy - CY) * k + ty + n.py;
        n.el.setAttribute('transform', 'translate(' + n.x.toFixed(1) + ' ' + n.y.toFixed(1) + ')' + (n.kind === 'hub' ? '' : ' scale(' + (0.6 + 0.4 * k).toFixed(3) + ')'));
        n.el.style.opacity = k;
      });
      edges.forEach(function (e) {
        var c = ctrl(e);
        e.c = c;
        e.el.setAttribute('d', 'M' + e.a.x.toFixed(1) + ' ' + e.a.y.toFixed(1) + 'Q' + c.x.toFixed(1) + ' ' + c.y.toFixed(1) + ' ' + e.b.x.toFixed(1) + ' ' + e.b.y.toFixed(1));
      });
      if (still) return;
      if (age >= 1 && time - lastPulse > 900) {
        lastPulse = time;
        var tool = active && Math.random() < 0.7 ? active : tools[Math.floor(Math.random() * tools.length)];
        if (!tool.data.soon) {
          var leaf = tool.topics[Math.floor(Math.random() * tool.topics.length)];
          pulses.push({ path: [tool.edge, leaf.edge], t: time, el: s('circle', { r: compact ? 5 : 2.75, class: 'hb-g-pulse' }, gPulse) });
        }
      }
      pulses = pulses.filter(function (p) {
        var q = (time - p.t) / 1800;
        if (q >= 1) { gPulse.removeChild(p.el); return false; }
        var e = q < 0.5 ? p.path[0] : p.path[1], f = q < 0.5 ? q * 2 : (q - 0.5) * 2;
        f = f * f * (3 - 2 * f);
        var pt = qpt(e.a, e.c, e.b, f);
        p.el.setAttribute('cx', pt.x.toFixed(1)); p.el.setAttribute('cy', pt.y.toFixed(1));
        p.el.style.opacity = q > 0.9 ? (1 - q) * 10 : 1;
        return true;
      });
      if (!touched && age >= 1 && time - lastCycle > 3600) {
        lastCycle = time; cycle = (cycle + 1) % tools.length;
        setActive(tools[cycle]);
      }
    }
    function frame(time) {
      if (t0 === null) t0 = time;
      place(time);
      if (running) requestAnimationFrame(frame);
    }
    function start() { if (!running && visible && !document.hidden) { running = true; requestAnimationFrame(frame); } }
    function stop() { running = false; }
    if (still) { place(0); return; }
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { visible = en[0].isIntersecting; visible ? start() : stop(); }).observe(fig);
    }
    document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
    start();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.hb-app'), initCommon);
    Array.prototype.forEach.call(document.querySelectorAll('.hubbub-checker'), initChecker);
    Array.prototype.forEach.call(document.querySelectorAll('.hubbub-crawlers'), initCrawlers);
    Array.prototype.forEach.call(document.querySelectorAll('.hubbub-ads'), initAds);
    Array.prototype.forEach.call(document.querySelectorAll('.hubbub-citations'), initCitations);
    Array.prototype.forEach.call(document.querySelectorAll('.hb-app'), initLines);
    Array.prototype.forEach.call(document.querySelectorAll('.hb-app'), initReveal);
    Array.prototype.forEach.call(document.querySelectorAll('.hb-graph[data-graph]'), initGraph);
  });
})();
