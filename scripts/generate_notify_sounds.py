#!/usr/bin/env python3
"""
ZVUKY UPOZORNĚNÍ AppleFix CRM — generátor (v3.88.0).

Vlastní, syntetizované zvuky (žádné cizí nahrávky → žádné licence). Každý zvuk
je krátký motiv z „paličkových" tónů (sinus + jemné neharmonické alikvoty jako
u kalimby/marimby), měkký nástup, přirozený doznívající dozvuk. Hlasitost je
srovnaná, aby žádný zvuk nevyskočil.

  afx_shift   — Směna začíná: přátelské vzestupné arpeggio
  afx_info    — Informace: dvojí lehké ťuknutí
  afx_warn    — Pozor: klidný, ale nepřehlédnutelný motiv
  afx_urgent  — Naléhavé: výraznější vzestupný motiv 2×
  afx_done    — Vyřešeno: rozkvétající durový akord
  afx_cash    — Pokladna: dvě jasná „mincová" cinknutí

Výstup (ffmpeg):  <name>.wav (zdroj), .caf (iOS, lpcm), .ogg (Android, Vorbis),
                  .m4a (web).
Spuštění:  python3 scripts/generate_notify_sounds.py <výstupní adresář>
"""
import os
import subprocess
import sys
import wave

import numpy as np

SR = 44100


def mallet(freq, dur, bright=1.0, decay=1.0):
    """Paličkový tón: základ + alikvoty, vyšší doznívají rychleji."""
    t = np.arange(int(SR * dur)) / SR
    partials = [(1.0, 1.0, 1.0), (2.0, 0.22 * bright, 1.8), (2.76, 0.10 * bright, 3.2),
                (5.40, 0.035 * bright, 6.0), (0.5, 0.06, 0.8)]
    out = np.zeros_like(t)
    for ratio, amp, dk in partials:
        f = freq * ratio
        if f > SR / 2.2:
            continue
        out += amp * np.sin(2 * np.pi * f * t) * np.exp(-t * dk * 4.2 / decay)
    attack = np.minimum(1.0, t / 0.004)              # 4 ms — bez lupnutí, ale svižně
    return out * attack


def reverb(x, mix=0.18, length=0.9):
    """Lehký prostor: konvoluce s exponenciálně tlumeným šumem (deterministický)."""
    rng = np.random.default_rng(7)
    n = int(SR * length)
    t = np.arange(n) / SR
    ir = rng.standard_normal(n) * np.exp(-t * 6.5)
    # tlumení výšek v dozvuku (jednoduchý jednopólový low-pass)
    for i in range(1, n):
        ir[i] = 0.55 * ir[i] + 0.45 * ir[i - 1]
    ir /= np.sqrt(np.sum(ir ** 2))
    size = len(x) + n
    nfft = 1 << (size - 1).bit_length()                # FFT konvoluce — přímá by trvala minuty
    wet = np.fft.irfft(np.fft.rfft(x, nfft) * np.fft.rfft(ir, nfft), nfft)[:size]
    dry = np.concatenate([x, np.zeros(n)])
    return (1 - mix) * dry + mix * wet


def motif(notes, total, bright=1.0, decay=1.0, gain=1.0):
    """notes = [(čas_s, frekvence_Hz, hlasitost), …]"""
    out = np.zeros(int(SR * total))
    for start, freq, amp in notes:
        tone = mallet(freq, total - start, bright, decay) * amp
        i = int(SR * start)
        out[i:i + len(tone)] += tone[: len(out) - i]
    return out * gain


def finish(x, peak_db=-2.0, fade=0.12):
    # oříznout neslyšitelný konec dozvuku (pod −54 dB), ať je soubor krátký
    peak = np.max(np.abs(x)) or 1.0
    loud = np.nonzero(np.abs(x) > peak * 0.002)[0]
    if len(loud):
        x = x[: min(len(x), loud[-1] + int(SR * 0.05))].copy()
    n = int(SR * fade)
    x[-n:] *= np.linspace(1, 0, n) ** 2
    x = x - np.mean(x)
    peak = np.max(np.abs(x)) or 1.0
    return x / peak * (10 ** (peak_db / 20))


# Ladění: E dur / A dur pentatonika — příjemné, nikdy disonantní
E5, Gs5, A5, B5, Cs6, E6, Fs6, A6, B6, E7 = 659.25, 830.61, 880.0, 987.77, 1108.73, 1318.51, 1479.98, 1760.0, 1975.53, 2637.02
G5, C6, D6 = 783.99, 1046.50, 1174.66

SOUNDS = {
    'afx_shift': lambda: motif([(0.00, E5, 0.85), (0.12, B5, 0.80), (0.24, E6, 0.75), (0.36, Gs5 * 2, 0.35)], 1.5, 0.9, 1.1),
    'afx_info': lambda: motif([(0.00, G5 * 1.5, 0.8), (0.085, D6 * 1.5, 0.6)], 0.9, 0.7, 0.7),
    'afx_warn': lambda: motif([(0.00, Cs6, 0.9), (0.15, A5, 0.85), (0.30, Cs6, 0.9), (0.30, E6, 0.35)], 1.4, 1.1, 1.0),
    'afx_urgent': lambda: motif([(0.00, A5, 0.8), (0.085, Cs6, 0.8), (0.17, E6, 0.85), (0.255, A6, 0.9),
                                 (0.62, A5, 0.8), (0.705, Cs6, 0.8), (0.79, E6, 0.85), (0.875, A6, 1.0)], 2.0, 1.4, 0.9),
    'afx_done': lambda: motif([(0.00, G5, 0.7), (0.07, C6, 0.7), (0.14, E6 * 1.0, 0.7), (0.30, G5 * 2, 0.45)], 1.5, 0.8, 1.3),
    'afx_cash': lambda: motif([(0.00, B6, 0.8), (0.11, E7, 0.7)], 0.85, 1.6, 0.6),
}


def write_wav(path, x):
    pcm = (np.clip(x, -1, 1) * 32767).astype('<i2')
    with wave.open(path, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(pcm.tobytes())


def ff(*args):
    subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', *args], check=True)


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else 'notify_sounds'
    os.makedirs(out, exist_ok=True)
    for name, make in SOUNDS.items():
        x = finish(reverb(make()))
        wav = os.path.join(out, name + '.wav')
        write_wav(wav, x)
        ff('-i', wav, '-c:a', 'pcm_s16le', '-f', 'caf', os.path.join(out, name + '.caf'))
        ff('-i', wav, '-c:a', 'libvorbis', '-q:a', '6', os.path.join(out, name + '.ogg'))
        ff('-i', wav, '-c:a', 'aac', '-b:a', '128k', os.path.join(out, name + '.m4a'))
        print(f'{name}: {len(x) / SR:.2f} s')


if __name__ == '__main__':
    main()
