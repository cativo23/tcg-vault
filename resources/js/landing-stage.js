/* tcg-vault landing v5 · one stage, five acts, one set of card pictures.
 *
 * The same card objects (one picture each) are laid out once per pose: stack → grid (shared page) → sets (divider tabs) → prices (slip + hang tags) → set (binder page) → close.
 * Scrolling interpolates every card between two poses with transform + opacity only. A passive scroll listener
 * feeds one requestAnimationFrame; nothing reads layout per frame.
 * Reduced motion (or ?motion=off) builds one static figure per act from the same layouts.
 * Debug: ?progress=0..1 drives the stage to that point of the story with no window scroll (headless captures).
 */
import D from './landing-demo.json';
if (document.getElementById('stage')) {
  var html = document.documentElement;
  var MOVING = html.classList.contains('moving');
  var DBG = html.classList.contains('dbg');
  var BASE = 300, RATIO = 342 / 245, BAR = 56;
  var POSES = ['stack', 'grid', 'sets', 'prices', 'set', 'close'];

  var clamp = function (v, a, b) { return Math.min(b === undefined ? 1 : b, Math.max(a || 0, v)); };
  var lerp = function (a, b, t) { return a + (b - a) * t; };
  var ease = function (t) { return t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
  var money = function (c, dp) {
    return '$' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: dp === 0 ? 0 : 2, maximumFractionDigits: dp === 0 ? 0 : 2 });
  };
  var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  var put = function (e, x, y, w, h) { e.style.left = x + 'px'; e.style.top = y + 'px'; if (w != null) e.style.width = w + 'px'; if (h != null) e.style.height = h + 'px'; };

  var POOL_IDS = {};
  D.pool.forEach(function (c) { POOL_IDS[c.id] = 1; });
  var POOL = D.pool.concat(D.binder.filter(function (c) { return !c.missing && !POOL_IDS[c.id]; }));
  POOL.forEach(function (c, i) { c.i = i; c.inPool = !!POOL_IDS[c.id]; });
  /* The hero stack is not in value order: the cards named in D.hero lead it (franchise mascot first), then the
   * rest of the pool by value. The grid and the price slip keep the pool's own value order. */
  var ORDER = [];
  (D.hero || []).forEach(function (id) {
    D.pool.forEach(function (c) { if (c.id === id) ORDER.push(c); });
  });
  D.pool.forEach(function (c) { if (ORDER.indexOf(c) < 0) ORDER.push(c); });
  ORDER.forEach(function (c, i) { c.rk = i; });
  var BY_NUM = {};
  D.binder.forEach(function (c) { BY_NUM[+c.num] = c; });
  var ROWS = D.pool.slice(0, 6);

  var H = D.history, hv = H.filter(function (p) { return p.v != null; });
  var lastDelta = hv[hv.length - 1].v - hv[hv.length - 2].v;
  var hMax = Math.max.apply(null, hv.map(function (p) { return p.v; }));
  var hMin = Math.min.apply(null, hv.map(function (p) { return p.v; }));
  var dLabel = function (iso) { var d = new Date(iso + 'T12:00:00Z'); return d.getUTCDate() + ' ' + d.toLocaleString('en-US', { month: 'short', timeZone: 'UTC' }); };

  function delta(c) {
    if (c.price_7d == null) return null;
    var d = c.price - c.price_7d;
    return { up: d > 0, text: (d > 0 ? '+' : d < 0 ? '−' : '±') + money(Math.abs(d)) + ' · 7d' };
  }

  /* a hang tag: price + 7-day move, tied on a string */
  function hangTag(c, withSrc, sw, sh, path) {
    var t = el('div', 'htag');
    var svg = '<svg class="string" width="' + sw + '" height="' + sh + '" viewBox="0 0 ' + sw + ' ' + sh + '" aria-hidden="true"><path d="' + path + '"/></svg>';
    var dl = delta(c);
    t.innerHTML = svg + '<span class="htag-face">' +
      (withSrc ? '<span class="htag-src">tcgplayer</span>' : '') +
      '<span class="htag-price"></span>' +
      (dl ? '<span class="htag-delta' + (dl.up ? ' up' : '') + '"></span>' : '') +
      '</span><span class="htag-hole"></span>';
    t.querySelector('.htag-price').textContent = money(c.price);
    if (dl) t.querySelector('.htag-delta').textContent = dl.text;
    return t;
  }

  /* ---------- grid fitting ---------- */
  function fitGrid(n, w, h, gap, minW) {
    var best;
    for (var k = n; k >= Math.min(n, 8); k--) {
      best = null;
      for (var cols = 1; cols <= k; cols++) {
        var rows = Math.ceil(k / cols);
        var cw = Math.min((w - gap * (cols - 1)) / cols, ((h - gap * (rows - 1)) / rows) / RATIO);
        if (!best || cw > best.cw) best = { cols: cols, rows: rows, cw: cw, n: k };
      }
      if (best.cw >= minW) break;
    }
    return best;
  }

  /* ---------- one Stage = one set of card objects + overlays ---------- */
  function Stage(root, fixedPose) {
    this.root = root;
    this.fixed = fixedPose || null;
    this.cards = [];
    this.pending = 0;
    this.build();
  }

  /* assign src to deferred cards: only the ones currently shown, or all of them */
  Stage.prototype.loadImages = function (all) {
    if (!this.pending) return;
    var self = this;
    this.cards.forEach(function (o) {
      if (!o.src || (!all && o.e.style.visibility === 'hidden')) return;
      o.img.src = o.src; o.src = null; self.pending--;
    });
  };

  Stage.prototype.build = function () {
    var root = this.root, self = this;
    POOL.forEach(function (c, i) {
      var e = el('div', 'obj live');
      var lift = el('div', 'lift');
      var img = new Image();
      img.className = 'face'; img.alt = ''; img.decoding = 'async';
      var lead = c.inPool ? c.rk < 2 : false;
      var src = c.img + (lead ? '/high.webp' : '/low.webp');
      img.onerror = function () { var ph = el('div', 'face noimg', c.name); img.replaceWith(ph); };
      lift.appendChild(img);
      e.appendChild(lift);
      e.style.zIndex = c.inPool ? 100 - c.rk : 60 + i;
      root.appendChild(e);
      var o = { c: c, e: e, last: '', img: img, src: src };
      /* the moving stage defers pictures until they are on screen or the gallery is near; static figures
       * only show their own pose, so the browser's lazy loading is enough */
      if (self.fixed) { img.loading = 'lazy'; img.src = src; o.src = null; }
      else if (lead) { img.src = src; o.src = null; }
      else self.pending++;
      self.cards.push(o);
    });

    /* the shared page (grid + sets) */
    this.sheetOv = el('div', 'ov fill');
    this.sheet = el('div', 'sheet');
    this.head = el('div', 'sheet-head');
    this.head.appendChild(el('span', 'addr', 'tcgvault.cativo.dev/demo'));
    this.cnt = el('span', 'cnt', D.count + ' cards');
    this.head.appendChild(this.cnt);
    this.sheetOv.appendChild(this.sheet); this.sheetOv.appendChild(this.head);
    this.labs = el('div', 'ov fill');

    /* hero: the chase card's hang tag */
    this.heroOv = el('div', 'ov fill');
    this.heroTag = hangTag(ORDER[0], true, 48, 34, 'M48 34 C 30 34, 12 26, 1 1');
    this.heroOv.appendChild(this.heroTag);

    /* the total: travels from the page head onto the price slip */
    this.total = el('div', 'ov total');
    var tv = money(D.total).split('.');
    this.total.appendChild(document.createTextNode(tv[0]));
    this.total.appendChild(el('small', '', '.' + tv[1]));

    /* price act */
    this.priceBg = el('div', 'ov fill');
    this.slip = el('div', 'slip'); this.priceBg.appendChild(this.slip);
    this.priceOv = el('div', 'ov fill');
    this.chart = el('div', 'chart');
    this.chart.innerHTML = this.chartSVG();
    this.wipe = el('div', 'wipe');
    this.chart.appendChild(this.wipe);
    this.priceOv.appendChild(this.chart);
    this.axes = [money(hMax, 0), money(hMin, 0), dLabel(H[0].d), dLabel(H[H.length - 1].d)].map(function (t) {
      var a = el('span', 'axis', t); self.priceOv.appendChild(a); return a;
    });
    this.today = el('div', 'today');
    this.today.appendChild(document.createTextNode(dLabel(H[H.length - 1].d) + ' · '));
    this.today.appendChild(el('span', lastDelta > 0 ? 'up' : '', (lastDelta > 0 ? '+' : '−') + money(Math.abs(lastDelta))));
    this.priceOv.appendChild(this.today);
    this.src = el('div', 'src', 'tcgplayer · USD · today and 7 days');
    this.priceOv.appendChild(this.src);
    this.rowEls = ROWS.map(function (c, i) {
      var r = el('div', 'prow');
      var t = hangTag(c, false, 30, 12, 'M30 6 C 20 12, 8 10, 0 4');
      t.querySelector('.string').style.cssText = 'right:auto;left:-20px;bottom:auto;top:calc(50% - 6px)';
      t.dataset.r = (i % 2 ? 2.2 : -1.8);
      var nm = el('div', 'nm');
      nm.appendChild(el('b', '', c.name));
      nm.appendChild(el('span', '', c.set_name));
      r.appendChild(t); r.appendChild(nm);
      self.priceOv.appendChild(r);
      return { r: r, t: t };
    });

    /* set act: binder spread (built per layout) */
    this.setBg = el('div', 'ov fill');
    this.setTop = el('div', 'ov fill');

    var z = { sheetOv: 3, labs: 4, priceBg: 3, setBg: 3, priceOv: 200, heroOv: 200, total: 210, setTop: 250 };
    Object.keys(z).forEach(function (k) { self[k].style.zIndex = z[k]; root.appendChild(self[k]); });
  };

  Stage.prototype.chartSVG = function () {
    var n = H.length, W = 1000, Hh = 300, pad = 12;
    var x = function (i) { return (i / (n - 1)) * W; };
    var y = function (v) { return pad + (1 - (v - hMin) / (hMax - hMin)) * (Hh - pad * 2); };
    var d = '', pen = false, gaps = '';
    H.forEach(function (p, i) {
      if (p.v == null) {
        if (pen) {
          var j = i; while (j < n && H[j].v == null) j++;
          if (j < n) gaps += '<line class="gap" x1="' + x(i - 1).toFixed(1) + '" y1="' + y(H[i - 1].v).toFixed(1) + '" x2="' + x(j).toFixed(1) + '" y2="' + y(H[j].v).toFixed(1) + '"/>';
        }
        pen = false; return;
      }
      d += (pen ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.v).toFixed(1);
      pen = true;
    });
    var grid = [0, .5, 1].map(function (f) { var yy = pad + f * (Hh - pad * 2); return '<line class="grid" x1="0" x2="' + W + '" y1="' + yy + '" y2="' + yy + '"/>'; }).join('');
    return '<svg viewBox="0 0 ' + W + ' ' + Hh + '" preserveAspectRatio="none" role="presentation">' + gaps + grid + '<path class="line" d="' + d + '"/></svg>';
  };

  /* ---------- poses ---------- */
  Stage.prototype.layout = function () {
    var W = this.root.clientWidth, Hh = this.root.clientHeight;
    if (!W || !Hh) return false;
    var still = !!this.fixed, mobile = W < (still ? 480 : 760);
    this.W = W; this.H = Hh; this.mobile = mobile; this.still = still;
    var R = {};
    var top = still ? 8 : BAR + (mobile ? 12 : clamp(Hh * .05, 16, 40));
    var bottom = still ? Hh - 8 : (mobile ? Hh * .54 - 12 : Hh - clamp(Hh * .045, 16, 40));
    var h = bottom - top;
    if (still) {
      R.stack = R.main = R.left = R.close = { x: mobile ? 6 : 12, y: top, w: W - (mobile ? 12 : 24), h: h };
    } else if (mobile) {
      R.main = R.left = R.close = { x: 16, y: top, w: W - 32, h: h };
      R.stack = { x: 16, y: BAR + 8, w: W - 32, h: Hh * .25 - 4 };
    } else {
      R.main = { x: W * .39, y: top, w: W * .57, h: h };
      R.left = { x: W * .04, y: top, w: W * .55, h: h };
      R.stack = { x: W * .57, y: top + 8, w: W * .39, h: h - 8 };
      R.close = { x: W * .58, y: top, w: W * .34, h: h };
    }
    this.tw = this.total.offsetWidth || 440;
    this.P = {}; this.T = {};
    this.P.stack = this.poseStack(R.stack, false);
    this.P.grid = this.poseGrid(R.main);
    this.P.sets = this.poseSets(R.main);
    this.P.prices = this.posePrices(R.left);
    this.P.set = this.poseSet(R.main);
    this.P.close = this.poseStack(R.close, true);
    this.fillHidden();
    return true;
  };

  Stage.prototype.poseStack = function (R, squared) {
    var out = {};
    var ch = Math.min(R.h * (squared ? .78 : .92), 620), cw = Math.min(ch / RATIO, R.w * (squared ? .6 : .56));
    var cx = R.x + R.w / 2, cy = R.y + R.h / 2;
    var fanR = [-1.5, 4.5, -7, 8.5, -11, 6, -4, 12, -9];
    var fanX = [0, .07, -.09, .13, -.15, .05, -.04, .17, -.12];
    ORDER.forEach(function (c, i) {
      var k = Math.min(i, 8), r, dx, dy;
      if (squared) { r = (i % 2 ? 1 : -1) * Math.min(i, 6) * .35; dx = (i % 3 - 1) * 1.2; dy = -i * 1.6; }
      else { r = fanR[k]; dx = fanX[k] * cw; dy = -k * cw * .012; }
      out[c.id] = { x: cx - cw / 2 + dx, y: cy - (cw * RATIO) / 2 + dy, w: cw, r: r, o: 1 };
    });
    if (!squared) {
      var t0 = out[ORDER[0].id];
      var tx = t0.x + t0.w * .84 + 36, ty = t0.y + t0.w * .1 + 34 - 30;
      this.T.heroTag = { x: Math.min(tx, this.W - 132), y: ty };
    }
    return out;
  };

  Stage.prototype.poseGrid = function (R) {
    var out = {}, m = this.mobile, gap = m ? 7 : 14, hh = m ? 34 : 44;
    var g = fitGrid(D.pool.length, R.w, R.h - hh, gap, m ? 56 : 80);
    D.pool.slice(0, g.n).forEach(function (c, i) {
      out[c.id] = { x: R.x + (i % g.cols) * (g.cw + gap), y: R.y + hh + Math.floor(i / g.cols) * (g.cw * RATIO + gap), w: g.cw, r: 0, o: 1 };
    });
    this.gridN = g.n;
    this.gridBox = { w: g.cols * g.cw + (g.cols - 1) * gap, h: hh + g.rows * (g.cw * RATIO + gap) - gap };
    var ts = (m ? 15 : 21) / 72;
    var cntW = m ? 0 : (this.cnt.offsetWidth || 70) + 16;
    this.T.grid = { R: R, hh: hh, total: { x: 0, y: R.y - (m ? 3 : 6), s: ts }, cntW: cntW };
    return out;
  };

  Stage.prototype.poseSets = function (R) {
    var out = {}, m = this.mobile, gap = m ? 6 : 10, lh = m ? 22 : 28, hh = this.T.grid.hh;
    var shown = D.pool.slice(0, this.gridN);
    var order = [], groups = {};
    shown.forEach(function (c) { if (!groups[c.set_id]) { groups[c.set_id] = []; order.push(c.set_id); } groups[c.set_id].push(c); });
    order.sort(function (a, b) { return groups[b].length - groups[a].length; });
    var units = order.filter(function (s) { return groups[s].length > 1; }).concat(order.filter(function (s) { return groups[s].length === 1; }))
      .map(function (s) { return groups[s]; });
    var place = function (cw) {
      var x = 0, row = 0, items = [], labels = [], sep = cw * .4, maxX = 0;
      units.forEach(function (u) {
        var need = u.length * cw + (u.length - 1) * gap;
        if (x > 0 && x + sep + Math.min(need, R.w) > R.w + .5) { x = 0; row++; }
        else if (x > 0) x += sep;
        labels.push({ x: x, row: row, s: u[0], n: u.length, w: Math.min(need, R.w - x) });
        u.forEach(function (c) {
          if (x + cw > R.w + .5) { x = 0; row++; }
          items.push({ c: c, x: x, row: row }); x += cw + gap; maxX = Math.max(maxX, x - gap);
        });
        x -= gap;
      });
      return { items: items, labels: labels, rows: row + 1, maxX: maxX };
    };
    var maxG = Math.max.apply(null, units.map(function (u) { return u.length; }));
    var lo = 20, hi = Math.max(40, Math.min(220, (R.w - gap * (maxG - 1)) / maxG)), res;
    for (var it = 0; it < 22; it++) {
      var mid = (lo + hi) / 2, p = place(mid);
      if (p.rows * (mid * RATIO + lh + gap) <= R.h - hh) lo = mid; else hi = mid;
    }
    var cw = lo; res = place(cw);
    var rowH = cw * RATIO + lh + gap;
    res.items.forEach(function (it) { out[it.c.id] = { x: R.x + it.x, y: R.y + hh + it.row * rowH + lh, w: cw, r: 0, o: 1 }; });
    this.setsBox = { w: res.maxX, h: hh + res.rows * rowH - gap };
    /* divider tabs: one per set, behind its first card, never wider than what is left of the page */
    this.labs.textContent = '';
    var small = cw < 110 || m;
    res.labels.forEach(function (l) {
      var e = el('div', 'dtab' + (small ? ' small' : ''));
      e.appendChild(el('span', 'nm', l.s.set_name));
      if (l.n > 1) e.appendChild(el('b', '', '×' + l.n));
      var left = R.x + l.x;
      var w = Math.max(24, Math.min(l.w, R.x + R.w - left));
      put(e, left, R.y + hh + l.row * rowH + 2, null, lh - 2);
      e.style.maxWidth = w + 'px';
      this.labs.appendChild(e);
    }, this);
    return out;
  };

  Stage.prototype.posePrices = function (R) {
    var out = {}, m = this.mobile, pad = m ? 12 : 22;
    var ts = m ? Math.min(.6, (R.w - 2 * pad) / this.tw) : Math.min(1.05, (R.w - 2 * pad - 150) / this.tw, (R.h * .15) / 72);
    var totH = 72 * ts;
    var x0 = R.x + pad, y0 = R.y + pad;
    var T = { total: { x: x0, y: y0, s: ts } };
    var todayY = m ? y0 + totH + 8 : y0 + totH - 14;
    T.today = { x: m ? x0 : x0 + this.tw * ts + 20, y: todayY };
    var axisW = m ? 0 : 58;
    var chartY = (m ? todayY + 14 : y0 + totH) + (m ? 14 : 22);
    var rowH = m ? 44 : clamp(R.h * .075, 44, 54), srcH = m ? 20 : 22, gapRows = m ? 10 : 18, axisH = 20;
    var avail = R.y + R.h - chartY - axisH - pad - gapRows - srcH;
    var cMin = m ? 40 : 96, cMax = m ? 150 : 280;
    var n = clamp(Math.floor((avail - cMin) / rowH), 2, m ? 3 : 6);
    var chartH = clamp(avail - n * rowH, m ? 48 : 56, cMax);
    T.chart = { x: x0 + axisW, y: chartY, w: R.w - 2 * pad - axisW, h: chartH };
    T.slip = { x: R.x, y: R.y, w: R.w, h: chartY + chartH + axisH + pad - R.y };
    var rowsY = R.y + T.slip.h + gapRows;
    T.src = { x: R.x + 4, y: rowsY };
    var th = rowH - 10, tw = th / RATIO;
    T.rows = [];
    ROWS.slice(0, n).forEach(function (c, i) {
      var ry = rowsY + srcH + i * rowH;
      out[c.id] = { x: R.x + 6, y: ry + 6, w: tw, r: 0, o: 1 };
      T.rows.push({ x: R.x + 6 + tw + 28, y: ry, w: R.w - tw - 34, h: rowH });
    });
    this.T.prices = T;
    return out;
  };

  /* binder spread: pockets for the end of 151, owned cards drop in around the ghosts */
  Stage.prototype.poseSet = function (R) {
    var out = {}, m = this.mobile;
    var tabH = m ? 28 : 32, stripH = (!m && !this.still) || (this.still && !m) ? 46 : 0;
    var cands = [{ p: 2, r: 3 }, { p: 1, r: 3 }, { p: 2, r: 2 }, { p: 1, r: 2 }];
    var geo = function (c) {
      var kh = c.r * RATIO + (c.r - 1) * .07 + .28;
      return Math.min(R.w / (c.p * 3.54), (R.h - tabH - stripH) / kh, 170);
    };
    var pick = null, minPW = m ? 66 : 72;
    cands.forEach(function (c) { c.pw = geo(c); if (!pick && c.pw >= minPW) pick = c; });
    if (!pick) pick = cands.reduce(function (a, b) { return b.pw > a.pw ? b : a; });
    var pw = pick.pw, ph = pw * RATIO, g = pw * .07, padO = pw * .14, padS = pw * .26;
    var pageW = pw * 3.54, pageH = pick.r * ph + (pick.r - 1) * g + 2 * padO, spreadW = pick.p * pageW;
    var N = pick.p * pick.r * 3, start = N >= 9 ? 207 - N + 1 : 200;
    var sx = R.x + (R.w - spreadW) / 2;
    var sy = R.y + tabH + Math.max(0, (R.h - tabH - stripH - pageH) * .4);
    var S = { pages: [], pockets: [], tab: null, strip: null, start: start, end: start + N - 1 };
    var k = 0;
    for (var p = 0; p < pick.p; p++) {
      var px = sx + p * pageW, spineRight = pick.p === 2 && p === 0;
      S.pages.push({ x: px, y: sy, w: pageW, h: pageH, holeX: spineRight ? px + pageW - padS / 2 : px + padS / 2 });
      var cx0 = spineRight ? px + padO : px + padS;
      for (var r = 0; r < pick.r; r++) {
        for (var c = 0; c < 3; c++) {
          var num = start + k, card = BY_NUM[num];
          var pk = { x: cx0 + c * (pw + g), y: sy + padO + r * (ph + g), w: pw, h: ph, card: card };
          S.pockets.push(pk);
          if (card && !card.missing) {
            var inset = pw * .035;
            out[card.id] = { x: pk.x + inset, y: pk.y + inset * RATIO, w: pw - 2 * inset, r: 0, o: 1, s: 0, ord: k, ordN: N };
            out[card.id].enter = { x: pk.x + inset, y: pk.y - ph * .45, w: pw - 2 * inset, r: (k % 2 ? 3 : -3) };
          }
          k++;
        }
      }
    }
    var last = S.pages[S.pages.length - 1];
    S.tab = { right: last.x + last.w * .9, y: sy - tabH + 3, h: tabH };
    if (stripH) S.strip = { x: sx, y: sy + pageH + 16, w: spreadW, h: 12 };
    this.T.set = S;
    return out;
  };

  /* hidden cards: park them where they last were (or will be), invisible */
  Stage.prototype.fillHidden = function () {
    var P = this.P;
    this.cards.forEach(function (o) {
      var id = o.c.id;
      POSES.forEach(function (name, k) {
        if (P[name][id]) return;
        var next = POSES[k + 1] && P[POSES[k + 1]][id];
        if (next && next.enter) { var en = next.enter; P[name][id] = { x: en.x, y: en.y, w: en.w, r: en.r, o: 0, s: 0 }; return; }
        var src = null;
        for (var d = 1; d < POSES.length && !src; d++) {
          var a = POSES[k - d] && P[POSES[k - d]][id], b = POSES[k + d] && P[POSES[k + d]][id];
          src = (a && a.o) ? a : (b && b.o) ? b : null;
        }
        src = src || { x: -400, y: -400, w: 100, r: 0 };
        P[name][id] = { x: src.x + src.w * .04, y: src.y + src.w * .06, w: src.w * .92, r: src.r, o: 0, s: src.s };
      });
    });
  };

  /* ---------- placing overlays (once per layout) ---------- */
  Stage.prototype.placeOverlays = function () {
    var T = this.T, m = this.mobile, self = this;

    /* shared page */
    var G = T.grid, pad = m ? 10 : 18;
    var bw = Math.max(this.gridBox.w, this.setsBox.w, Math.min(G.R.w, 320)), bh = Math.max(this.gridBox.h, this.setsBox.h);
    put(this.sheet, G.R.x - pad, G.R.y - pad, bw + 2 * pad, bh + 2 * pad);
    put(this.head, G.R.x, G.R.y, bw, G.hh - (m ? 10 : 14));
    var ts = G.total.s;
    G.total.x = G.R.x + bw - G.cntW - this.tw * ts;
    if (m) G.total.x = G.R.x + bw - this.tw * ts;
    T.sets = { total: G.total };

    /* hero tag */
    put(this.heroTag, T.heroTag.x, T.heroTag.y);
    this.heroTag.style.transform = 'rotate(10deg)';

    /* price slip, chart, rows */
    var P = T.prices, c = P.chart;
    put(this.slip, P.slip.x, P.slip.y, P.slip.w, P.slip.h);
    put(this.chart, c.x, c.y, c.w, c.h);
    put(this.axes[0], c.x - (m ? 0 : 58), c.y - 4);
    put(this.axes[1], c.x - (m ? 0 : 58), c.y + c.h - 10);
    put(this.axes[2], c.x, c.y + c.h + 8);
    put(this.axes[3], c.x + c.w - 44, c.y + c.h + 8);
    if (m) { put(this.axes[0], c.x + c.w - 58, c.y - 15); put(this.axes[1], c.x + c.w / 2 - 26, c.y + c.h + 8); }
    put(this.today, P.today.x, P.today.y);
    put(this.src, P.src.x, P.src.y);
    this.rowEls.forEach(function (o, i) {
      var r = P.rows[i];
      o.r.style.display = r ? '' : 'none';
      if (r) put(o.r, r.x, r.y, r.w, r.h);
    });

    /* binder spread */
    var S = T.set;
    this.setBg.textContent = ''; this.setTop.textContent = '';
    var owned = 0, have = {};
    D.set151.owned.forEach(function (n) { have[n] = 1; });
    var tab = el('div', 'settab');
    tab.appendChild(document.createTextNode(D.set151.name));
    tab.appendChild(el('span', 'mono', D.set151.owned.length + ' / ' + D.set151.total));
    this.setBg.appendChild(tab);
    S.pages.forEach(function (pg) {
      var e = el('div', 'bpage'); put(e, pg.x, pg.y, pg.w, pg.h);
      [.2, .5, .8].forEach(function (f) { var hole = el('i'); put(hole, pg.holeX - pg.x - 4.5, pg.h * f - 4.5); e.appendChild(hole); });
      self.setBg.appendChild(e);
    });
    S.pockets.forEach(function (pk) {
      var sheen = el('div', 'pocket-sheen'); put(sheen, pk.x - pk.w * .02, pk.y - pk.w * .02, pk.w * 1.04, pk.h + pk.w * .04);
      self.setTop.appendChild(sheen);
      if (pk.card && pk.card.missing) {
        var gh = el('div', 'ghost'), inset = pk.w * .035;
        put(gh, pk.x + inset, pk.y + inset * RATIO, pk.w - 2 * inset, pk.h - 2 * inset * RATIO);
        var img = new Image(); img.alt = ''; img.decoding = 'async'; img.src = pk.card.img + '/low.webp';
        img.onerror = function () { img.remove(); };
        gh.appendChild(img);
        self.setBg.appendChild(gh);
        var gp = el('span', 'gprice', money(pk.card.price));
        put(gp, pk.x + pk.w / 2, pk.y + pk.h * .72);
        self.setTop.appendChild(gp);
      }
    });
    this.setBg.appendChild(tab);
    tab.style.top = S.tab.y + 'px'; tab.style.height = S.tab.h + 'px';
    tab.style.left = Math.max(S.pages[0].x, S.tab.right - (tab.offsetWidth || 120)) + 'px';
    this.cellsOn = [];
    this.bracket = null;
    if (S.strip) {
      var st = el('div', 'strip'); put(st, S.strip.x, S.strip.y, S.strip.w, S.strip.h);
      for (var n = 1; n <= D.set151.total; n++) {
        var t = el('span', have[n] ? '' : 'miss'); st.appendChild(t);
        if (have[n]) this.cellsOn.push(t);
      }
      this.setBg.appendChild(st);
      var tickW = S.strip.w / D.set151.total;
      var br = el('div', 'bracket'); put(br, S.strip.x + (S.start - 1) * tickW, S.strip.y + S.strip.h + 4, (S.end - S.start + 1) * tickW - 1);
      this.setBg.appendChild(br); this.bracket = br;
      var lab = el('span', 'bracket-lab', '#' + S.start + '–' + S.end);
      put(lab, S.strip.x + (S.start - 1) * tickW - 64, S.strip.y + S.strip.h + 1);
      this.setBg.appendChild(lab);
    }
    this.on = -1;
  };

  /* ---------- render one moment ---------- */
  Stage.prototype.render = function (a, b, t, chartP, fillP) {
    var Pa = this.P[POSES[a]], Pb = this.P[POSES[b]], n = this.cards.length;
    var dealing = a === 0 && b === 1;
    this.cards.forEach(function (o, i) {
      var A = Pa[o.c.id], B = Pb[o.c.id], delay, dur = .6;
      if (dealing) delay = (i / n) * .4;
      else if (B.ord != null && !A.o) { delay = .2 + (B.ord / B.ordN) * .5; dur = .3; }
      else delay = ((i % 12) / 12) * .4;
      var ti = ease(clamp((t - delay) / dur));
      var x = lerp(A.x, B.x, ti), y = lerp(A.y, B.y, ti), w = lerp(A.w, B.w, ti), r = lerp(A.r, B.r, ti), op = lerp(A.o, B.o, ti);
      var tr = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) rotate(' + r.toFixed(2) + 'deg) scale(' + (w / BASE).toFixed(4) + ')';
      var key = tr + op.toFixed(3);
      if (key !== o.last) { o.e.style.transform = tr; o.e.style.opacity = op.toFixed(3); o.e.style.visibility = op < .005 ? 'hidden' : 'visible'; o.last = key; }
    });
    var te = ease(t), wt = function (name) { var k = POSES.indexOf(name); return (a === k ? 1 - te : 0) + (b === k ? te : 0); };
    var wa = function () { var s = 0; for (var i = 0; i < arguments.length; i++) s += wt(arguments[i]); return clamp(s); };
    this.sheetOv.style.opacity = wa('grid', 'sets');
    this.labs.style.opacity = wt('sets');
    this.heroOv.style.opacity = wt('stack');
    this.priceBg.style.opacity = this.priceOv.style.opacity = wt('prices');
    this.setBg.style.opacity = this.setTop.style.opacity = wt('set');
    /* total */
    var T = this.T, ta = T[POSES[a]] && T[POSES[a]].total, tb = T[POSES[b]] && T[POSES[b]].total;
    var from = ta || tb, to = tb || ta;
    if (from) {
      var x = lerp(from.x, to.x, te), y = lerp(from.y, to.y, te), s = lerp(from.s, to.s, te);
      this.total.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) scale(' + s.toFixed(4) + ')';
    }
    this.total.style.opacity = wa('grid', 'sets', 'prices');
    /* chart draws; the hang tags swing in one after another as their prices are shown */
    var cp = clamp(chartP);
    this.wipe.style.transform = 'scaleX(' + (1 - cp).toFixed(4) + ')';
    this.rowEls.forEach(function (o, i) {
      var k = ease(clamp((cp * 1.3 - i * .1) / .35)), r0 = +o.t.dataset.r;
      o.r.style.opacity = k.toFixed(3);
      o.t.style.transform = 'translateY(' + ((1 - k) * -10).toFixed(1) + 'px) rotate(' + (r0 + (1 - k) * 14).toFixed(2) + 'deg)';
    });
    /* the set strip fills left to right; missing numbers stay hollow */
    var on = Math.round(clamp(fillP) * this.cellsOn.length);
    if (on !== this.on) { this.cellsOn.forEach(function (c, i) { c.classList.toggle('on', i < on); }); this.on = on; }
    if (this.bracket) this.bracket.style.transform = 'scaleX(' + clamp((fillP - .8) / .2).toFixed(3) + ')';
  };

  /* ================= wiring ================= */
  var acts = {};
  Array.prototype.forEach.call(document.querySelectorAll('.act'), function (a) { acts[a.dataset.act] = a; });

  if (!MOVING) {
    var stills = [];
    Array.prototype.forEach.call(document.querySelectorAll('.still-fig'), function (f) { stills.push(new Stage(f, f.dataset.pose)); });
    var drawStill = function () {
      stills.forEach(function (s) {
        if (!s.layout()) return;
        s.placeOverlays();
        var k = POSES.indexOf(s.fixed);
        s.render(k, k, 1, 1, 1);
        /* a static figure only keeps what its pose shows */
        s.cards.forEach(function (o) { o.e.style.display = s.P[s.fixed][o.c.id].o ? '' : 'none'; });
        s.total.style.display = (s.fixed === 'prices' || s.fixed === 'sets') ? '' : 'none';
      });
    };
    drawStill();
    window.addEventListener('resize', drawStill);
    if (document.fonts) document.fonts.ready.then(drawStill);
  } else {

  var stage = new Stage(document.getElementById('stage'));
  var actsEl = document.getElementById('acts');
  var segs = [], loadAt = Infinity, ticking = false, copies = [], cf = 80;
  var copyOf = function (n) { return acts[n].querySelector('.copy'); };
  var getY = function () { return DBG ? actsEl.scrollTop : window.scrollY; };
  var top = function (n) { return acts[n].offsetTop; };

  function measure() {
    var vh = DBG ? actsEl.clientHeight : window.innerHeight;
    if (!stage.layout()) return;
    stage.placeOverlays();
    var G = top('gallery'), P = top('prices'), S = top('set'), C = top('close');
    segs = [
      { a: 0, b: 1, s0: .04 * vh, s1: G - .12 * vh },
      { a: 1, b: 2, s0: G + .45 * vh, s1: G + 1.05 * vh },
      { a: 2, b: 3, s0: P - .55 * vh, s1: P + .05 * vh },
      { a: 3, b: 4, s0: S - .55 * vh, s1: S + .05 * vh },
      { a: 4, b: 5, s0: C - .8 * vh, s1: C - .1 * vh }
    ];
    loadAt = G - .9 * vh;
    stage.chartSpan = [P + .05 * vh, P + .8 * vh];
    /* copy windows: an act's copy is fully on while its own stage content is (from the end of the move that brought it
     * in to the start of the move that takes it out) and fades over `cf` of scroll at each end */
    cf = .1 * vh;
    copies = [
      { els: [copyOf('gallery')], inY: segs[0].s1, outY: segs[2].s0 },
      { els: [copyOf('prices')], inY: segs[2].s1, outY: segs[3].s0 },
      { els: [copyOf('set')], inY: segs[3].s1, outY: segs[4].s0 },
      { els: [copyOf('close'), document.querySelector('.closing')], inY: segs[4].s1, outY: Infinity }
    ];
    stage.fillSpan = [S + .1 * vh, S + .9 * vh];
    frame();
  }

  function frame() {
    ticking = false;
    var y = getY(), a = 0, b = 0, t = 0;
    for (var i = 0; i < segs.length; i++) {
      var s = segs[i];
      if (y < s.s0) break;
      a = s.a; b = s.b; t = clamp((y - s.s0) / (s.s1 - s.s0));
      if (t >= 1) { a = s.b; t = 0; b = s.b; }
    }
    if (!stage.chartSpan) return;
    var cp = (y - stage.chartSpan[0]) / (stage.chartSpan[1] - stage.chartSpan[0]);
    var fp = (y - stage.fillSpan[0]) / (stage.fillSpan[1] - stage.fillSpan[0]);
    stage.render(a, b, t, cp, fp);
    /* everything beyond the first act loads once the gallery is about to enter the viewport (a jump-scroll
     * past it passes the same test); until then only the cards currently shown load */
    stage.loadImages(y >= loadAt);
    copies.forEach(function (c) {
      var k = ease(clamp((y - c.inY) / cf)), o = k * clamp((c.outY - y) / cf);
      c.els.forEach(function (e) {
        if (!e) return;
        e.style.opacity = o.toFixed(3);
        e.style.transform = k < 1 ? 'translate3d(0,' + ((1 - k) * 16).toFixed(1) + 'px,0)' : '';
      });
    });
  }

  var onScroll = function () { if (!ticking) { ticking = true; requestAnimationFrame(frame); } };
  (DBG ? actsEl : window).addEventListener('scroll', onScroll, { passive: true });
  var rz; window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(function () { measure(); if (DBG) goProgress(); }, 80); });

  /* reduced motion turned on mid-visit: switch to the static page */
  var mq = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)');
  if (mq && mq.addEventListener) mq.addEventListener('change', function (e) { if (e.matches) location.reload(); });

  /* debug hook: ?progress=0..1 scrolls the acts layer (not the window) and renders the stage at that point */
  var prog = clamp(parseFloat(new URLSearchParams(location.search).get('progress')) || 0);
  function goProgress() {
    actsEl.scrollTop = prog * (actsEl.scrollHeight - actsEl.clientHeight);
    frame();
  }
  measure();
  if (DBG) goProgress();

  /* ?progress jumps straight into the story, so the debug view loads everything up front */
  if (DBG) stage.loadImages(true);
  if (document.fonts) document.fonts.ready.then(function () { measure(); if (DBG) goProgress(); });
}
}
