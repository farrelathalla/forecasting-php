/* Plain canvas charts. No CDN, no bundler, no dependency.
 *
 * Deliberate: a plant network is often closed, and a chart library that fails to load
 * is a dashboard that does not open. Everything here works from the local filesystem. */

(function () {
  'use strict';

  function tick() {
    var el = document.getElementById('clock');
    if (el) {
      var d = new Date();
      el.textContent = d.toLocaleString('id-ID', { hour12: false });
    }
  }
  // This file is loaded in <head>, before the DOM exists, so that inline scripts further
  // down the page can call fetchJson/drawChart. tick() is null-safe; the listener below
  // paints the clock as soon as there is something to paint.
  tick();
  document.addEventListener('DOMContentLoaded', tick);
  setInterval(tick, 1000);

  function css(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  function niceStep(range, targetTicks) {
    var raw = range / Math.max(1, targetTicks);
    var mag = Math.pow(10, Math.floor(Math.log10(raw || 1)));
    var norm = raw / mag;
    var step = norm >= 5 ? 10 : norm >= 2 ? 5 : norm >= 1 ? 2 : 1;
    return step * mag;
  }

  /**
   * series: [{points:[{x:Date|number,y:number|null}], color, width, dash}]
   * band:   {points:[{x, lo, hi}], color}
   */
  window.drawChart = function (canvas, opts) {
    if (!canvas) return;
    var dpr = window.devicePixelRatio || 1;
    var cssWidth = canvas.parentNode.clientWidth || 800;
    var cssHeight = opts.height || 280;
    canvas.width = cssWidth * dpr;
    canvas.height = cssHeight * dpr;
    canvas.style.height = cssHeight + 'px';

    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssWidth, cssHeight);

    var pad = { l: 58, r: 12, t: 10, b: 26 };
    var w = cssWidth - pad.l - pad.r;
    var h = cssHeight - pad.t - pad.b;

    var xs = [], ys = [];
    (opts.series || []).forEach(function (s) {
      s.points.forEach(function (p) {
        if (p.y === null || p.y === undefined || !isFinite(p.y)) return;
        xs.push(+p.x); ys.push(p.y);
      });
    });
    if (opts.band) {
      opts.band.points.forEach(function (p) {
        if (p.lo === null || p.hi === null) return;
        xs.push(+p.x); ys.push(p.lo); ys.push(p.hi);
      });
    }
    if (!xs.length) {
      ctx.fillStyle = css('--muted');
      ctx.font = '13px system-ui';
      ctx.textAlign = 'center';
      ctx.fillText('belum ada data', cssWidth / 2, cssHeight / 2);
      return;
    }

    var x0 = Math.min.apply(null, xs), x1 = Math.max.apply(null, xs);
    var y0 = Math.min.apply(null, ys), y1 = Math.max.apply(null, ys);
    if (y0 === y1) { y0 -= 1; y1 += 1; }
    var padY = (y1 - y0) * 0.08;
    y0 -= padY; y1 += padY;
    if (x1 === x0) x1 = x0 + 1;

    function X(v) { return pad.l + (v - x0) / (x1 - x0) * w; }
    function Y(v) { return pad.t + h - (v - y0) / (y1 - y0) * h; }

    // grid + y axis
    ctx.strokeStyle = css('--line');
    ctx.fillStyle = css('--muted');
    ctx.lineWidth = 1;
    ctx.font = '11px system-ui';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';

    var step = niceStep(y1 - y0, 5);
    for (var v = Math.ceil(y0 / step) * step; v <= y1; v += step) {
      var y = Y(v);
      ctx.globalAlpha = 0.35;
      ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + w, y); ctx.stroke();
      ctx.globalAlpha = 1;
      ctx.fillText(Math.abs(v) >= 1000 ? v.toFixed(0) : v.toFixed(2), pad.l - 7, y);
    }

    // x axis labels
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (var i = 0; i <= 4; i++) {
      var xv = x0 + (x1 - x0) * i / 4;
      var d = new Date(xv);
      var label = d.getHours().toString().padStart(2, '0') + ':' +
                  d.getMinutes().toString().padStart(2, '0');
      if (i === 0 || i === 4 || (x1 - x0) > 36 * 3600 * 1000) {
        label = (d.getMonth() + 1) + '/' + d.getDate() + ' ' + label;
      }
      ctx.fillText(label, X(xv), pad.t + h + 6);
    }

    // marker line (forecast origin)
    if (opts.marker) {
      var mx = X(+opts.marker);
      ctx.save();
      ctx.strokeStyle = css('--accent');
      ctx.globalAlpha = 0.6;
      ctx.setLineDash([4, 4]);
      ctx.beginPath(); ctx.moveTo(mx, pad.t); ctx.lineTo(mx, pad.t + h); ctx.stroke();
      ctx.restore();
    }

    // prediction band
    if (opts.band && opts.band.points.length) {
      var pts = opts.band.points.filter(function (p) { return p.lo !== null && p.hi !== null; });
      if (pts.length) {
        ctx.beginPath();
        ctx.moveTo(X(+pts[0].x), Y(pts[0].hi));
        pts.forEach(function (p) { ctx.lineTo(X(+p.x), Y(p.hi)); });
        for (var j = pts.length - 1; j >= 0; j--) { ctx.lineTo(X(+pts[j].x), Y(pts[j].lo)); }
        ctx.closePath();
        ctx.fillStyle = opts.band.color || css('--band');
        ctx.fill();
      }
    }

    // series, with nulls breaking the line rather than being bridged
    (opts.series || []).forEach(function (s) {
      ctx.strokeStyle = s.color || css('--accent');
      ctx.lineWidth = s.width || 1.6;
      ctx.setLineDash(s.dash || []);
      ctx.beginPath();
      var pen = false;
      s.points.forEach(function (p) {
        if (p.y === null || p.y === undefined || !isFinite(p.y)) { pen = false; return; }
        var px = X(+p.x), py = Y(p.y);
        if (!pen) { ctx.moveTo(px, py); pen = true; } else { ctx.lineTo(px, py); }
      });
      ctx.stroke();
      ctx.setLineDash([]);
    });
  };

  window.fetchJson = function (url) {
    return fetch(url, { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  };

  window.fmt = function (v, digits) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    return Number(v).toFixed(digits === undefined ? 2 : digits);
  };
})();
