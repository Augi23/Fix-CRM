/* AppleFix CRM — animované pozadí „zvlněná halftone síť"
 * Samostatný web component, žádné závislosti.
 * Použití: <af-wave-bg></af-wave-bg> (vyplní rodiče, position:absolute)
 * Barva se pomalu protáčí celou škálou — výchozí plný cyklus 10 minut.
 */
(function () {
  'use strict';
  const TAU = 6.2831853;

  class AFWaveBg extends HTMLElement {
    static get observedAttributes() {
      return ['speed', 'wavelength', 'amplitude', 'spacing', 'hue', 'zivost', 'jas', 'cycle', 'static'];
    }

    connectedCallback() {
      if (!this._canvas) {
        this.style.cssText += ';position:absolute;inset:0;overflow:hidden;pointer-events:none;display:block';
        this._canvas = document.createElement('canvas');
        this._canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;display:block';
        this.appendChild(this._canvas);
      }
      this._ro = new ResizeObserver(() => this._resize());
      this._ro.observe(this);
      this._resize();
      this._start = performance.now();
      this._reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      this._tick = (now) => {
        this._draw((now - this._start) / 1000);
        if (!this._reduced && !this.hasAttribute('static')) {
          this._raf = requestAnimationFrame(this._tick);
        }
      };
      this._raf = requestAnimationFrame(this._tick);
    }

    disconnectedCallback() {
      cancelAnimationFrame(this._raf);
      if (this._ro) this._ro.disconnect();
    }

    attributeChangedCallback() {
      if (this._canvas && (this._reduced || this.hasAttribute('static'))) {
        this._draw(0);
      }
    }

    _num(name, fallback) {
      const v = parseFloat(this.getAttribute(name));
      return isNaN(v) ? fallback : v;
    }

    _resize() {
      const c = this._canvas;
      if (!c) return;
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      this._w = this.clientWidth || 1200;
      this._h = this.clientHeight || 700;
      this._dpr = dpr;
      c.width = Math.round(this._w * dpr);
      c.height = Math.round(this._h * dpr);
      if (this._reduced || this.hasAttribute('static')) this._draw(0);
    }

    // Nepravidelné pole: šest vlnových vlaků, bloudící fáze, pomalé obálky.
    _field(x, y, t, lam, seed) {
      const drift = 1.9 * Math.sin(TAU * (0.41 * x - 0.23 * y) / (lam * 2.3) - t * 0.13 + seed)
                  + 1.1 * Math.sin(TAU * (0.19 * x + 0.52 * y) / (lam * 3.7) + t * 0.09)
                  + 0.6 * Math.sin(TAU * (0.71 * x + 0.09 * y) / (lam * 1.53) - t * 0.21);
      const e1 = 0.55 + 0.45 * Math.sin(TAU * (0.13 * x + 0.31 * y) / (lam * 4.1) + t * 0.11 + seed);
      const e2 = 0.55 + 0.45 * Math.sin(TAU * (0.29 * x - 0.17 * y) / (lam * 3.3) - t * 0.07);
      return 0.36 * e1 * Math.sin(TAU * (0.62 * x + 0.78 * y) / lam - t * 0.5 + drift + seed)
           + 0.22 * e2 * Math.sin(TAU * (x - 0.4 * y) / (lam * 0.617) + t * 0.37 + 0.5 * drift)
           + 0.19 * e1 * Math.sin(TAU * (0.78 * y - 0.62 * x) / (lam * 1.373) - t * 0.28 + seed * 1.7 + 0.4 * drift)
           + 0.13 * e2 * Math.sin(TAU * (0.86 * x - 0.51 * y) / (lam * 0.409) + t * 0.61)
           + 0.12 * Math.sin(TAU * (0.2 * x - y) / (lam * 2.81) - t * 0.15 + seed)
           + 0.09 * Math.sin(TAU * (0.94 * x + 0.34 * y) / (lam * 0.283) + t * 0.44 + drift);
    }

    _layer(ctx, t, step, rBase, color, maxAlpha, maskBias, lam, amp) {
      const W = this._w, H = this._h;
      const buckets = new Map();
      for (let gy = step * 0.5; gy < H + step; gy += step) {
        for (let gx = step * 0.5; gx < W + step; gx += step) {
          const p = (gx / W) * 0.58 + (gy / H) * 0.74;
          let mask = 1 - p / maskBias;
          if (mask <= 0.012) continue;
          mask = Math.pow(Math.min(mask, 1), 1.3);
          const h = this._field(gx, gy, t, lam, 0);
          const slope = this._field(gx + 9, gy + 4, t, lam, 0) - this._field(gx - 9, gy - 4, t, lam, 0);
          const lift = (h + 1) / 2;
          const a = mask * (0.58 + 0.30 * lift + 0.34 * slope) * maxAlpha;
          if (a < 0.012) continue;
          const key = Math.round(a * 60);
          let path = buckets.get(key);
          if (!path) { path = new Path2D(); buckets.set(key, path); }
          const depth = 1 + h * amp * 0.022;
          const r = rBase * depth * (0.9 + 0.22 * lift);
          const x = gx + ((gx - W / 2) / (W / 2)) * h * amp;
          const y = gy + ((gy - H / 2) / (H / 2)) * h * amp;
          path.moveTo(x + r, y);
          path.arc(x, y, r, 0, TAU);
        }
      }
      ctx.fillStyle = color;
      for (const entry of buckets) {
        ctx.globalAlpha = entry[0] / 60;
        ctx.fill(entry[1]);
      }
      ctx.globalAlpha = 1;
    }

    // OKLCH drží barevnost i u světlých tónů — jas a živost se nepřebíjejí.
    _oklch(jas, zivost, hue) {
      const L = 40 + (jas - 35) * 0.87;
      const C = (zivost / 100) * 0.17;
      return 'oklch(' + L.toFixed(1) + '% ' + C.toFixed(3) + ' ' + (hue % 360).toFixed(1) + ')';
    }

    _draw(rawT) {
      const c = this._canvas;
      if (!c) return;
      if (this.clientWidth !== this._w || this.clientHeight !== this._h) this._resize();
      const ctx = c.getContext('2d');
      const t = rawT * this._num('speed', 2);
      const lam = this._num('wavelength', 360);
      const amp = this._num('amplitude', 14);
      const step = this._num('spacing', 15);
      // pomalé protáčení odstínu: celá škála za `cycle` sekund (výchozí 600 s)
      const cycle = this._num('cycle', 600);
      const hue = this._num('hue', 185) + (cycle > 0 ? (rawT / cycle) * 360 : 0);
      const color = this._oklch(this._num('jas', 95), this._num('zivost', 100), hue);
      ctx.setTransform(this._dpr, 0, 0, this._dpr, 0, 0);
      ctx.clearRect(0, 0, this._w, this._h);
      this._layer(ctx, t, step, 1.05, color, 0.72, 1.02, lam, amp);
    }
  }

  if (!customElements.get('af-wave-bg')) {
    customElements.define('af-wave-bg', AFWaveBg);
  }
})();
