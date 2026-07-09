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
  <!-- silnější „čočka" jen pro OKRAJOVÝ prstenec skla (edge refraction, macOS Liquid Glass) -->
  <filter id="lg-refract-edge" x="-10%" y="-10%" width="120%" height="120%">
    <feTurbulence type="fractalNoise" baseFrequency="0.0045 0.0045" numOctaves="2" seed="7" result="noise"/>
    <feGaussianBlur in="noise" stdDeviation="3" result="blurred"/>
    <feDisplacementMap in="SourceGraphic" in2="blurred" scale="110" xChannelSelector="R" yChannelSelector="G"/>
  </filter>
  <!-- KARTY (login panel, klientská sekce): střední refrakce dle Apple Liquid Glass
       (baseFrequency 0.008, scale 72 = ideální střed — viditelný ohyb, ale čitelné). -->
  <filter id="lg-glass" x="-5%" y="-5%" width="110%" height="110%">
    <feTurbulence type="fractalNoise" baseFrequency="0.008 0.008" numOctaves="2" seed="42" result="noise"/>
    <feGaussianBlur in="noise" stdDeviation="2" result="blurred"/>
    <feDisplacementMap in="SourceGraphic" in2="blurred" scale="72" xChannelSelector="R" yChannelSelector="G"/>
  </filter>
</svg>
