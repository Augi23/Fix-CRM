<?php /* Liquid Glass — SVG refrakční filtr (feTurbulence + feDisplacementMap).
   Vkládá se hned za <body>. Odkazuje se z assets/css/liquid-glass.css
   přes backdrop-filter: url(#lg-refract) — funguje v Chromium,
   Safari/Firefox mají fallback blur+saturate. */ ?>
<svg aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">
  <filter id="lg-refract" x="0%" y="0%" width="100%" height="100%">
    <feTurbulence type="fractalNoise" baseFrequency="0.007 0.007" numOctaves="2" seed="92" result="noise"/>
    <feGaussianBlur in="noise" stdDeviation="2" result="blurred"/>
    <feDisplacementMap in="SourceGraphic" in2="blurred" scale="52" xChannelSelector="R" yChannelSelector="G"/>
  </filter>
</svg>
