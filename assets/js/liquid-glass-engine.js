/*!
 * AppleFix Liquid Glass Engine — fyzikální refrakce (Snell → displacement mapa
 * → SVG feDisplacementMap na backdrop-filter). Port ze skillu apple-liquid-glass.
 *
 * Použití:  AFXGlass.attach(el, opts)  |  data-afx-glass="dock|panel|modal" (auto)
 * Chromium = plná refrakce; Safari/Firefox = CSS fallback blur (viz crm-shell.css).
 * Mapy se přepočítávají na přesnou velikost prvku (ResizeObserver) — libovolná okna.
 */
(function () {
  'use strict';

  var SURFACE_FNS = {
    convex_squircle: function (x) { return Math.pow(1 - Math.pow(1 - x, 4), 0.25); },
    convex_circle: function (x) { return Math.sqrt(1 - (1 - x) * (1 - x)); },
    concave: function (x) { return 1 - Math.sqrt(1 - (1 - x) * (1 - x)); }
  };

  var DEFAULTS = {
    surface: 'convex_squircle',
    radius: 24, thickness: 44, bezel: 20, ior: 1.8, scaleRatio: 0.85,
    blur: 2.2, specularOpacity: 0.32, specularSaturation: 3,
    tintColor: '#0d1119', tintOpacity: 13,
    shadowColor: 'rgba(255,255,255,0.20)', shadowBlur: 10, shadowSpread: -7,
    outerShadowBlur: 34
  };

  // Presety odsouhlasené v náhledu (dok / okno-modál / jemný panel)
  var PRESETS = {
    dock:  { radius: 24, ior: 1.7, thickness: 34, bezel: 16, scaleRatio: 0.8, blur: 2.4,
             specularOpacity: 0.3, tintColor: '#10141d', tintOpacity: 22,
             shadowColor: 'rgba(255,255,255,0.16)', shadowBlur: 9, shadowSpread: -7, outerShadowBlur: 30 },
    modal: { radius: 18, ior: 2.0, thickness: 52, bezel: 24, scaleRatio: 1.0, blur: 1.8,
             specularOpacity: 0.3, tintColor: '#0d1119', tintOpacity: 30,
             shadowColor: 'rgba(255,255,255,0.14)', shadowBlur: 10, shadowSpread: -7, outerShadowBlur: 40 },
    panel: { radius: 16, ior: 1.6, thickness: 36, bezel: 16, scaleRatio: 0.75, blur: 2.6,
             specularOpacity: 0.28, tintColor: '#0e1216', tintOpacity: 26,
             shadowColor: 'rgba(255,255,255,0.14)', shadowBlur: 9, shadowSpread: -7, outerShadowBlur: 26 }
  };

  // Vykresluje url() backdrop-filter jen Chromium (Safari/FF parsují, ale nekreslí).
  var SUPPORTS_SVG_BACKDROP =
    (typeof CSS !== 'undefined' && CSS.supports && CSS.supports('background', 'paint(x)')) ||
    (typeof navigator !== 'undefined' && /Chrom(e|ium)\//.test(navigator.userAgent || ''));

  function calcProfile(thickness, bezel, heightFn, ior, samples) {
    samples = samples || 128;
    var eta = 1 / ior;
    var profile = new Float64Array(samples);
    for (var i = 0; i < samples; i++) {
      var x = i / samples;
      var y = heightFn(x);
      var dx = x < 1 ? 0.0001 : -0.0001;
      var deriv = (heightFn(x + dx) - y) / dx;
      var mag = Math.sqrt(deriv * deriv + 1);
      var nx = -deriv / mag, ny = -1 / mag;
      var dot = ny;
      var k = 1 - eta * eta * (1 - dot * dot);
      if (k < 0) { profile[i] = 0; continue; }
      var sq = Math.sqrt(k);
      var rx = -(eta * dot + sq) * nx;
      var ry = eta - (eta * dot + sq) * ny;
      profile[i] = rx * ((y * bezel + thickness) / ry);
    }
    return profile;
  }

  function genDispMap(w, h, radius, bezel, profile, maxDisp) {
    var c = document.createElement('canvas'); c.width = w; c.height = h;
    var ctx = c.getContext('2d');
    var img = ctx.createImageData(w, h), d = img.data;
    for (var i = 0; i < d.length; i += 4) { d[i] = 128; d[i + 1] = 128; d[i + 2] = 0; d[i + 3] = 255; }
    var r = radius, rSq = r * r, r1Sq = (r + 1) * (r + 1);
    var rBSq = Math.max(r - bezel, 0); rBSq *= rBSq;
    var wB = w - r * 2, hB = h - r * 2, S = profile.length;
    for (var y1 = 0; y1 < h; y1++) {
      for (var x1 = 0; x1 < w; x1++) {
        var x = x1 < r ? x1 - r : x1 >= w - r ? x1 - r - wB : 0;
        var y = y1 < r ? y1 - r : y1 >= h - r ? y1 - r - hB : 0;
        var dSq = x * x + y * y;
        if (dSq > r1Sq || dSq < rBSq) continue;
        var dist = Math.sqrt(dSq);
        var fromSide = r - dist;
        var op = dSq < rSq ? 1 : 1 - (dist - Math.sqrt(rSq)) / (Math.sqrt(r1Sq) - Math.sqrt(rSq));
        if (op <= 0 || dist === 0) continue;
        var cos = x / dist, sin = y / dist;
        var bi = Math.min(((fromSide / bezel) * S) | 0, S - 1);
        var disp = profile[bi] || 0;
        var idx = (y1 * w + x1) * 4;
        d[idx] = (128 + ((-cos * disp) / maxDisp) * 127 * op + 0.5) | 0;
        d[idx + 1] = (128 + ((-sin * disp) / maxDisp) * 127 * op + 0.5) | 0;
      }
    }
    ctx.putImageData(img, 0, 0);
    return c.toDataURL();
  }

  function genSpecMap(w, h, radius, bezel, angle) {
    angle = angle != null ? angle : Math.PI / 3;
    var c = document.createElement('canvas'); c.width = w; c.height = h;
    var ctx = c.getContext('2d');
    var img = ctx.createImageData(w, h), d = img.data;
    var r = radius, rSq = r * r, r1Sq = (r + 1) * (r + 1);
    var rBSq = Math.max(r - bezel, 0); rBSq *= rBSq;
    var wB = w - r * 2, hB = h - r * 2;
    var svx = Math.cos(angle), svy = Math.sin(angle);
    for (var y1 = 0; y1 < h; y1++) {
      for (var x1 = 0; x1 < w; x1++) {
        var x = x1 < r ? x1 - r : x1 >= w - r ? x1 - r - wB : 0;
        var y = y1 < r ? y1 - r : y1 >= h - r ? y1 - r - hB : 0;
        var dSq = x * x + y * y;
        if (dSq > r1Sq || dSq < rBSq) continue;
        var dist = Math.sqrt(dSq);
        var fromSide = r - dist;
        var op = dSq < rSq ? 1 : 1 - (dist - Math.sqrt(rSq)) / (Math.sqrt(r1Sq) - Math.sqrt(rSq));
        if (op <= 0 || dist === 0) continue;
        var cos = x / dist, sin = -y / dist;
        var dot = Math.abs(cos * svx + sin * svy);
        var e1 = 1 - fromSide;
        var edge = Math.sqrt(Math.max(0, 1 - e1 * e1));
        var coeff = dot * edge;
        var col = (255 * coeff) | 0;
        var idx = (y1 * w + x1) * 4;
        d[idx] = col; d[idx + 1] = col; d[idx + 2] = col;
        d[idx + 3] = (col * coeff * op) | 0;
      }
    }
    ctx.putImageData(img, 0, 0);
    return c.toDataURL();
  }

  function ensureDefs() {
    var svg = document.getElementById('afx-glass-host');
    if (!svg) {
      svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('id', 'afx-glass-host');
      svg.setAttribute('width', '0'); svg.setAttribute('height', '0');
      svg.setAttribute('color-interpolation-filters', 'sRGB');
      svg.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none';
      svg.appendChild(document.createElementNS('http://www.w3.org/2000/svg', 'defs'));
      document.body.appendChild(svg);
    }
    return svg.firstChild;
  }

  // Jen primitiva — rodičovský <filter> (id + region) vzniká v attach().
  function primitives(w, h, dispUrl, specUrl, scale, blurAmt, specSat, specOpacity) {
    return '<feGaussianBlur in="SourceGraphic" stdDeviation="' + blurAmt + '" result="b"/>' +
      '<feImage href="' + dispUrl + '" x="0" y="0" width="' + w + '" height="' + h + '" result="dm"/>' +
      '<feDisplacementMap in="b" in2="dm" scale="' + scale + '" xChannelSelector="R" yChannelSelector="G" result="d"/>' +
      '<feColorMatrix in="d" type="saturate" values="' + specSat + '" result="ds"/>' +
      '<feImage href="' + specUrl + '" x="0" y="0" width="' + w + '" height="' + h + '" result="sl"/>' +
      '<feComposite in="ds" in2="sl" operator="in" result="sm"/>' +
      '<feComponentTransfer in="sl" result="sf"><feFuncA type="linear" slope="' + specOpacity + '"/></feComponentTransfer>' +
      '<feBlend in="sm" in2="d" mode="normal" result="ws"/>' +
      '<feBlend in="sf" in2="ws" mode="normal"/>';
  }

  function hexToRgb(hex) {
    return parseInt(hex.slice(1, 3), 16) + ', ' + parseInt(hex.slice(3, 5), 16) + ', ' + parseInt(hex.slice(5, 7), 16);
  }

  var seq = 0;

  function attach(el, options) {
    if (!el || el.__afxGlass) return el && el.__afxGlass;
    var opts = {}; var k;
    for (k in DEFAULTS) opts[k] = DEFAULTS[k];
    for (k in (options || {})) opts[k] = options[k];

    var defs = ensureDefs();
    var id = 'afx-lg-' + (++seq) + '-' + Math.random().toString(36).slice(2, 7);
    var NS = 'http://www.w3.org/2000/svg';
    var filter = document.createElementNS(NS, 'filter');
    filter.setAttribute('id', id);
    filter.setAttribute('x', '0%'); filter.setAttribute('y', '0%');
    filter.setAttribute('width', '100%'); filter.setAttribute('height', '100%');
    defs.appendChild(filter);

    el.classList.add('afx-glass');
    if (SUPPORTS_SVG_BACKDROP) el.style.setProperty('--afx-filter', 'url(#' + id + ')');

    var s = el.style;
    s.setProperty('--afx-radius', opts.radius + 'px');
    s.setProperty('--afx-tint-color', hexToRgb(opts.tintColor));
    s.setProperty('--afx-tint-opacity', (opts.tintOpacity / 100).toFixed(3));
    s.setProperty('--afx-shadow-color', opts.shadowColor);
    s.setProperty('--afx-shadow-blur', opts.shadowBlur + 'px');
    s.setProperty('--afx-shadow-spread', opts.shadowSpread + 'px');
    s.setProperty('--afx-outer-shadow-blur', opts.outerShadowBlur + 'px');

    var destroyed = false, lastSig = '';
    function render() {
      if (destroyed || !SUPPORTS_SVG_BACKDROP) return;
      var w = el.offsetWidth, h = el.offsetHeight;
      if (w < 2 || h < 2) return;
      var r = Math.min(opts.radius, Math.floor(Math.min(w, h) / 2));
      var heightFn = SURFACE_FNS[opts.surface] || SURFACE_FNS.convex_squircle;
      var bez = Math.min(opts.bezel, r - 1, Math.min(w, h) / 2 - 1);
      var sig = [w, h, r, opts.thickness, bez, opts.ior, opts.scaleRatio, opts.blur,
        opts.specularSaturation, opts.specularOpacity].join('|');
      if (sig === lastSig) return;
      lastSig = sig;
      var profile = calcProfile(opts.thickness, bez, heightFn, opts.ior, 128);
      var maxDisp = 1;
      for (var i = 0; i < profile.length; i++) { var a = Math.abs(profile[i]); if (a > maxDisp) maxDisp = a; }
      var dispUrl = genDispMap(w, h, r, bez, profile, maxDisp);
      var specUrl = genSpecMap(w, h, r, bez * 2.5);
      filter.innerHTML = primitives(w, h, dispUrl, specUrl, maxDisp * opts.scaleRatio,
        opts.blur, opts.specularSaturation, opts.specularOpacity);
    }

    var rafId = 0;
    function schedule() { cancelAnimationFrame(rafId); rafId = requestAnimationFrame(render); }
    var bootId = requestAnimationFrame(function () { bootId = requestAnimationFrame(render); });
    var ro = new ResizeObserver(schedule);
    ro.observe(el);

    var handle = {
      id: id, render: render,
      destroy: function () {
        destroyed = true; ro.disconnect();
        cancelAnimationFrame(rafId); cancelAnimationFrame(bootId);
        filter.remove(); el.classList.remove('afx-glass');
        delete el.__afxGlass;
      }
    };
    el.__afxGlass = handle;
    return handle;
  }

  // Auto-attach: prvky s data-afx-glass="dock|modal|panel" (i modály — skryté
  // mají velikost 0, render se ohlídá a doběhne přes ResizeObserver po otevření).
  function autoAttach(root) {
    var scope = root || document;
    var nodes = scope.querySelectorAll('[data-afx-glass]');
    for (var i = 0; i < nodes.length; i++) {
      var preset = PRESETS[nodes[i].getAttribute('data-afx-glass')] || PRESETS.panel;
      attach(nodes[i], preset);
    }
    // Modály CRM = skleněná okna (skryté mají velikost 0 → dobakne po otevření)
    var modals = scope.querySelectorAll('.modal-content.glass-card');
    for (var j = 0; j < modals.length; j++) attach(modals[j], PRESETS.modal);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { autoAttach(); });
  } else {
    autoAttach();
  }

  window.AFXGlass = { attach: attach, autoAttach: autoAttach, PRESETS: PRESETS, supportsRefraction: SUPPORTS_SVG_BACKDROP };
})();
