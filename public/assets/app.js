/* Plain canvas charts. No CDN, no bundler, no dependency.
 *
 * Deliberate: a plant network is often closed, and a chart library that fails to load
 * is a dashboard that does not open. Everything here works from the local filesystem.
 *
 * Loaded in <head> so inline scripts further down the page can call these helpers. */

(function () {
  'use strict';

  function tick() {
    var el = document.getElementById('clock');
    if (el) {
      el.textContent = new Date().toLocaleString('id-ID', { hour12: false });
    }
  }
  // Runs at head time, before the DOM exists, so tick() is null-safe; the listener
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

  function fmtTime(d, wide) {
    var hh = String(d.getHours()).padStart(2, '0');
    var mm = String(d.getMinutes()).padStart(2, '0');
    var t = hh + ':' + mm;
    return wide ? (d.getMonth() + 1) + '/' + d.getDate() + ' ' + t : t;
  }

  /**
   * series: [{points:[{x:Date|number,y:number|null}], color, width, dash, label}]
   * band:   {points:[{x, lo, hi}], color, label}
   * marker: Date|number      vertical rule, e.g. the forecast origin
   * height: px
   */
  window.drawChart = function (canvas, opts) {
    if (!canvas) return;

    // Keep the inputs so hover can repaint the exact same picture underneath the
    // crosshair, and so a resize can redraw without the caller refetching.
    canvas.__chartOpts = opts;

    var dpr = window.devicePixelRatio || 1;
    var cssWidth = (canvas.parentNode && canvas.parentNode.clientWidth) || 800;
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
      canvas.__chartGeom = null;
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

    canvas.__chartGeom = { x0: x0, x1: x1, y0: y0, y1: y1, pad: pad, w: w, h: h,
                           cssWidth: cssWidth, cssHeight: cssHeight, X: X, Y: Y };

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

    var wide = (x1 - x0) > 36 * 3600 * 1000;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (var i = 0; i <= 4; i++) {
      var xv = x0 + (x1 - x0) * i / 4;
      ctx.fillText(fmtTime(new Date(xv), wide || i === 0 || i === 4), X(xv), pad.t + h + 6);
    }

    if (opts.marker) {
      var mx = X(+opts.marker);
      ctx.save();
      ctx.strokeStyle = css('--accent');
      ctx.globalAlpha = 0.6;
      ctx.setLineDash([4, 4]);
      ctx.beginPath(); ctx.moveTo(mx, pad.t); ctx.lineTo(mx, pad.t + h); ctx.stroke();
      ctx.restore();
    }

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

    // Nulls break the line rather than being bridged: a gap in the history is a gap,
    // and drawing through it would invent operation that never happened.
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

    if (!canvas.__hoverBound) {
      bindHover(canvas);
      canvas.__hoverBound = true;
    }
  };

  /** Nearest point of a series to a data-space x, ignoring nulls. */
  function nearest(points, targetX) {
    var best = null, bestDist = Infinity;
    for (var i = 0; i < points.length; i++) {
      var p = points[i];
      if (p.y === null || p.y === undefined || !isFinite(p.y)) continue;
      var d = Math.abs(+p.x - targetX);
      if (d < bestDist) { bestDist = d; best = p; }
    }
    return best ? { point: best, dist: bestDist } : null;
  }

  function nearestBand(points, targetX) {
    var best = null, bestDist = Infinity;
    for (var i = 0; i < points.length; i++) {
      var p = points[i];
      if (p.lo === null || p.hi === null) continue;
      var d = Math.abs(+p.x - targetX);
      if (d < bestDist) { bestDist = d; best = p; }
    }
    return best;
  }

  function bindHover(canvas) {
    canvas.style.cursor = 'crosshair';

    canvas.addEventListener('mouseleave', function () {
      if (canvas.__chartOpts) window.drawChart(canvas, canvas.__chartOpts);
    });

    canvas.addEventListener('mousemove', function (event) {
      var geom = canvas.__chartGeom;
      var opts = canvas.__chartOpts;
      if (!geom || !opts) return;

      var rect = canvas.getBoundingClientRect();
      var mx = event.clientX - rect.left;
      var my = event.clientY - rect.top;
      if (mx < geom.pad.l || mx > geom.pad.l + geom.w) {
        window.drawChart(canvas, opts);
        return;
      }

      // Repaint the base picture, then overlay. Redrawing a few thousand points per
      // mousemove is well within budget for canvas and keeps the state trivial.
      window.drawChart(canvas, opts);
      geom = canvas.__chartGeom;

      var ctx = canvas.getContext('2d');
      var dataX = geom.x0 + (mx - geom.pad.l) / geom.w * (geom.x1 - geom.x0);

      var rows = [];
      var snapX = null;
      (opts.series || []).forEach(function (s) {
        if (!s.label) return;
        var hit = nearest(s.points, dataX);
        if (!hit) return;
        if (snapX === null || hit.dist < Math.abs(snapX - dataX)) snapX = +hit.point.x;
        rows.push({ label: s.label, value: hit.point.y, color: s.color || css('--accent') });
      });

      if (opts.band && opts.band.points.length) {
        var b = nearestBand(opts.band.points, dataX);
        if (b) {
          rows.push({ label: opts.band.label || 'interval 80%',
                      value: null, range: [b.lo, b.hi], color: css('--accent') });
        }
      }

      if (!rows.length) return;
      if (snapX === null) snapX = dataX;

      var lineX = geom.X(snapX);
      ctx.save();
      ctx.strokeStyle = css('--muted');
      ctx.globalAlpha = 0.55;
      ctx.setLineDash([3, 3]);
      ctx.beginPath();
      ctx.moveTo(lineX, geom.pad.t);
      ctx.lineTo(lineX, geom.pad.t + geom.h);
      ctx.stroke();
      ctx.restore();

      rows.forEach(function (r) {
        if (r.value === null || !isFinite(r.value)) return;
        ctx.fillStyle = r.color;
        ctx.beginPath();
        ctx.arc(lineX, geom.Y(r.value), 3.5, 0, Math.PI * 2);
        ctx.fill();
      });

      var title = fmtTime(new Date(snapX), true);
      var lines = [title].concat(rows.map(function (r) {
        if (r.range) {
          return r.label + ': ' + window.fmt(r.range[0]) + ' … ' + window.fmt(r.range[1]);
        }
        return r.label + ': ' + window.fmt(r.value);
      }));

      ctx.font = '12px system-ui';
      var boxW = 0;
      lines.forEach(function (line) { boxW = Math.max(boxW, ctx.measureText(line).width); });
      boxW += 20;
      var boxH = lines.length * 16 + 12;

      var bx = lineX + 12;
      if (bx + boxW > geom.cssWidth - 4) bx = lineX - boxW - 12;
      var by = Math.min(Math.max(my - boxH / 2, geom.pad.t), geom.pad.t + geom.h - boxH);

      ctx.save();
      ctx.globalAlpha = 0.96;
      ctx.fillStyle = css('--panel');
      ctx.strokeStyle = css('--line');
      ctx.lineWidth = 1;
      if (ctx.roundRect) {
        ctx.beginPath(); ctx.roundRect(bx, by, boxW, boxH, 6); ctx.fill(); ctx.stroke();
      } else {
        ctx.fillRect(bx, by, boxW, boxH); ctx.strokeRect(bx, by, boxW, boxH);
      }
      ctx.restore();

      ctx.textAlign = 'left';
      ctx.textBaseline = 'top';
      lines.forEach(function (line, i) {
        if (i === 0) {
          ctx.fillStyle = css('--muted');
        } else {
          ctx.fillStyle = rows[i - 1] ? rows[i - 1].color : css('--text');
        }
        ctx.fillText(line, bx + 10, by + 8 + i * 16);
      });
    });
  }

  /** Redraws every chart on the page from its stored options. */
  window.redrawAll = function () {
    Array.prototype.forEach.call(document.querySelectorAll('canvas'), function (c) {
      if (c.__chartOpts) window.drawChart(c, c.__chartOpts);
    });
  };

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(window.redrawAll, 150);
  });

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

  /** 'YYYY-MM-DD HH:MM:SS' from the API into a Date, Safari included. */
  window.toDate = function (ts) {
    return new Date(String(ts).replace(' ', 'T'));
  };

  window.cssVar = css;
})();
