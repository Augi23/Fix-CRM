#!/usr/bin/env python3
"""Čtení připojeného iPhonu/iPadu přes USB (libimobiledevice) → údaje pro CRM.

Proč vlastní modul a ne 3uTools: 3uTools je jen pro Windows (na macOS oficiální
build neexistuje). `libimobiledevice` je zdarma, open-source a přes USB přečte
i to, co placené IMEI služby neumí — hlavně KONDICI BATERIE (cykly a reálnou
kapacitu), kterou dnes obsluha píše ručně.

Parsování je schválně oddělené od spouštění příkazů, aby se dalo testovat na
uložených výstupech bez připojeného telefonu (scripts/device_bridge_test.py).
"""

from __future__ import annotations

import json
import re
import subprocess
from typing import Optional

# ── identifikátor zařízení → marketingový název ───────────────────────────────
# ProductType („iPhone13,2") je jediné, co telefon o sobě řekne; obchodní název
# v něm není. Tabulka pokrývá kusy, které reálně vykupujeme; co v ní není,
# se do formuláře NEVYPLNÍ (radši prázdno než špatný model).
PRODUCT_TYPES: dict[str, str] = {
    # iPhone
    "iPhone8,1": "iPhone 6s", "iPhone8,2": "iPhone 6s Plus", "iPhone8,4": "iPhone SE 2016",
    "iPhone9,1": "iPhone 7", "iPhone9,3": "iPhone 7", "iPhone9,2": "iPhone 7 Plus", "iPhone9,4": "iPhone 7 Plus",
    "iPhone10,1": "iPhone 8", "iPhone10,4": "iPhone 8", "iPhone10,2": "iPhone 8 Plus", "iPhone10,5": "iPhone 8 Plus",
    "iPhone10,3": "iPhone X", "iPhone10,6": "iPhone X",
    "iPhone11,8": "iPhone XR", "iPhone11,2": "iPhone XS", "iPhone11,4": "iPhone XS Max", "iPhone11,6": "iPhone XS Max",
    "iPhone12,1": "iPhone 11", "iPhone12,3": "iPhone 11 Pro", "iPhone12,5": "iPhone 11 Pro Max",
    "iPhone12,8": "iPhone SE 2020",
    "iPhone13,1": "iPhone 12 mini", "iPhone13,2": "iPhone 12", "iPhone13,3": "iPhone 12 Pro",
    "iPhone13,4": "iPhone 12 Pro Max",
    "iPhone14,4": "iPhone 13 mini", "iPhone14,5": "iPhone 13", "iPhone14,2": "iPhone 13 Pro",
    "iPhone14,3": "iPhone 13 Pro Max", "iPhone14,6": "iPhone SE 2022",
    "iPhone14,7": "iPhone 14", "iPhone14,8": "iPhone 14 Plus", "iPhone15,2": "iPhone 14 Pro",
    "iPhone15,3": "iPhone 14 Pro Max",
    "iPhone15,4": "iPhone 15", "iPhone15,5": "iPhone 15 Plus", "iPhone16,1": "iPhone 15 Pro",
    "iPhone16,2": "iPhone 15 Pro Max",
    "iPhone17,3": "iPhone 16", "iPhone17,4": "iPhone 16 Plus", "iPhone17,1": "iPhone 16 Pro",
    "iPhone17,2": "iPhone 16 Pro Max", "iPhone17,5": "iPhone 16e",
    "iPhone18,1": "iPhone 17 Pro", "iPhone18,2": "iPhone 17 Pro Max", "iPhone18,3": "iPhone 17",
    # iPad (jen ty, co má katalog; zbytek se nechá na obsluze)
    "iPad11,6": "iPad 8", "iPad11,7": "iPad 8", "iPad12,1": "iPad 9", "iPad12,2": "iPad 9",
    "iPad13,18": "iPad 10", "iPad13,19": "iPad 10",
    "iPad11,3": "iPad Air 3", "iPad11,4": "iPad Air 3", "iPad13,1": "iPad Air 4", "iPad13,2": "iPad Air 4",
    "iPad13,16": "iPad Air 5", "iPad13,17": "iPad Air 5",
    "iPad14,1": "iPad mini 6", "iPad14,2": "iPad mini 6",
    "iPad11,1": "iPad mini 5", "iPad11,2": "iPad mini 5",
}

# barevné kódy z DeviceEnclosureColor/DeviceColor — Apple je nedokumentuje,
# jisté jsou jen ty základní; ostatní se raději nehádají (špatná barva
# v inzerátu je horší než nevyplněná)
ENCLOSURE_COLORS: dict[str, str] = {
    "1": "Black", "2": "White", "3": "Gold", "4": "Silver", "5": "Rose Gold",
    "#1f2020": "Space Black", "#3b3b3c": "Space Gray", "#e1e4e3": "Silver",
    "#e3ccb4": "Gold", "#000000": "Black", "#ffffff": "White",
}

# běžné velikosti úložiště (GB) — telefon hlásí syrové bajty
STORAGE_STEPS = [16, 32, 64, 128, 256, 512, 1024, 2048]


def parse_ideviceinfo(text: str) -> dict[str, str]:
    """Výstup `ideviceinfo` (řádky „Klíč: hodnota") → slovník."""
    out: dict[str, str] = {}
    for line in (text or "").splitlines():
        if ":" not in line:
            continue
        key, _, value = line.partition(":")
        key, value = key.strip(), value.strip()
        if key:
            out[key] = value
    return out


def parse_ioregentry(text: str) -> dict[str, str]:
    """Výstup `idevicediagnostics ioregentry AppleSmartBattery` (XML plist
    nebo řádky) → slovník. Bere se jen to, co potřebujeme pro kondici."""
    out: dict[str, str] = {}
    # XML plist: <key>CycleCount</key><integer>412</integer>
    for k, v in re.findall(r"<key>([^<]+)</key>\s*<(?:integer|string|real)>([^<]*)</", text or ""):
        out[k.strip()] = v.strip()
    if not out:
        out = parse_ideviceinfo(text)
    return out


def storage_gb(total_bytes: int) -> Optional[int]:
    """Syrová kapacita disku → nejbližší obchodní velikost (64 GB, 128 GB…).
    Telefon hlásí míň, než je na krabici (systém + rezerva), proto se hledá
    nejbližší VYŠŠÍ běžná hodnota s tolerancí."""
    if not total_bytes or total_bytes <= 0:
        return None
    gb = total_bytes / 1_000_000_000
    for step in STORAGE_STEPS:
        if gb <= step * 1.02:      # 63,98 GB → 64; 119 GB → 128
            return step
    return None


def battery_health(smart: dict[str, str]) -> dict[str, Optional[int]]:
    """Kondice baterie z AppleSmartBattery. Vrací cykly a procenta.
    Novější iOS hlásí NominalChargeCapacity, starší AppleRawMaxCapacity."""
    def num(key: str) -> Optional[int]:
        raw = smart.get(key)
        if raw is None:
            return None
        try:
            return int(float(str(raw).strip()))
        except (TypeError, ValueError):
            return None

    design = num("DesignCapacity")
    actual = num("NominalChargeCapacity")
    if actual is None:
        actual = num("AppleRawMaxCapacity")
    pct = None
    if design and actual and design > 0:
        pct = int(round(actual * 100 / design))
        if pct > 100:
            pct = 100
        if pct <= 0:
            pct = None
    return {"cycles": num("CycleCount"), "health_pct": pct,
            "design_mah": design, "actual_mah": actual}


def build_snapshot(info: dict[str, str], disk: dict[str, str],
                   battery: dict[str, str], smart: dict[str, str]) -> dict:
    """Složí z jednotlivých výpisů to, co CRM potřebuje pro naskladnění."""
    product_type = info.get("ProductType", "")
    device_class = info.get("DeviceClass", "")
    model = PRODUCT_TYPES.get(product_type, "")

    try:
        total = int(disk.get("TotalDiskCapacity") or 0)
    except (TypeError, ValueError):
        total = 0
    gb = storage_gb(total)
    capacity = ""
    if gb:
        capacity = f"{gb // 1024} TB" if gb >= 1024 else f"{gb} GB"

    color_code = (info.get("DeviceEnclosureColor") or info.get("DeviceColor") or "").strip().lower()
    color = ENCLOSURE_COLORS.get(color_code, "")

    bat = battery_health(smart)
    try:
        charge = int(battery.get("BatteryCurrentCapacity") or 0) or None
    except (TypeError, ValueError):
        charge = None

    return {
        "imei": info.get("InternationalMobileEquipmentIdentity", ""),
        "imei2": info.get("InternationalMobileEquipmentIdentity2", ""),
        "serial": info.get("SerialNumber", ""),
        "udid": info.get("UniqueDeviceID", ""),
        "device_class": device_class,                  # iPhone / iPad
        "product_type": product_type,                  # iPhone13,2
        "model": model,                                # iPhone 12 (prázdné = neznáme)
        "model_number": info.get("ModelNumber", ""),   # MGJ83
        "region": info.get("RegionInfo", ""),
        "ios": info.get("ProductVersion", ""),
        "capacity": capacity,
        "capacity_bytes": total,
        "color": color,
        "color_code": color_code,
        "activation": info.get("ActivationState", ""),
        "battery_pct": charge,
        "battery_cycles": bat["cycles"],
        "battery_health": bat["health_pct"],
        "device_name": info.get("DeviceName", ""),
    }


# ── spouštění nástrojů (na Macu s libimobiledevice) ───────────────────────────

def _run(args: list[str], timeout: int = 12) -> str:
    try:
        res = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
        return res.stdout if res.returncode == 0 else ""
    except (OSError, subprocess.SubprocessError):
        return ""


def connected_udids() -> list[str]:
    return [u.strip() for u in _run(["idevice_id", "-l"]).splitlines() if u.strip()]


def read_device(udid: str) -> Optional[dict]:
    """Přečte připojené zařízení. None = nepodařilo se (odemkni a povol „Důvěřovat")."""
    info = parse_ideviceinfo(_run(["ideviceinfo", "-u", udid]))
    if not info:
        return None
    disk = parse_ideviceinfo(_run(["ideviceinfo", "-u", udid, "-q", "com.apple.disk_usage"]))
    battery = parse_ideviceinfo(_run(["ideviceinfo", "-u", udid, "-q", "com.apple.mobile.battery"]))
    smart = parse_ioregentry(_run(["idevicediagnostics", "-u", udid, "ioregentry", "AppleSmartBattery"]))
    snap = build_snapshot(info, disk, battery, smart)
    snap["udid"] = snap["udid"] or udid
    return snap


if __name__ == "__main__":   # ruční zkouška: python3 afx_device.py
    udids = connected_udids()
    if not udids:
        print("Není připojené žádné zařízení (nebo chybí libimobiledevice).")
    else:
        for u in udids:
            print(json.dumps(read_device(u), ensure_ascii=False, indent=2))
