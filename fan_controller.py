#!/usr/bin/env python3
import json
import math
import time
import logging
import requests
import paramiko
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# ── Configuration ─────────────────────────────────────────────────────────────

ILO_HOST      = "your-ilo-address"
ILO_USER      = "your-ilo-username"
ILO_PASS      = "your-ilo-password"
FAN_COUNT     = 6
POLL_INTERVAL = 60  # seconds

# Path to fan curve JSON file (shared with web UI)
FAN_CURVE_PATH = "/var/www/html/fan_curve.json"

# Fallback curve if file is missing or invalid
DEFAULT_CURVE = [(0, 10), (40, 10), (55, 20), (65, 45), (75, 75), (85, 100)]

# Sensors used to drive the fan curve — hottest reading wins
SENSORS = {
    "01-Inlet Ambient", "02-CPU 1", "03-CPU 2", "08-HD Max", "27-HD Controller",
}

# ── Logging ───────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-7s  %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)

# ── Core ──────────────────────────────────────────────────────────────────────

def load_fan_curve():
    try:
        with open(FAN_CURVE_PATH) as f:
            data = json.load(f)
        return [(p[0], p[1]) for p in data]
    except Exception:
        return DEFAULT_CURVE


def get_temperatures():
    url = f"https://{ILO_HOST}/redfish/v1/chassis/1/Thermal/"
    r = requests.get(url, auth=(ILO_USER, ILO_PASS), verify=False, timeout=10)
    r.raise_for_status()
    out = {}
    for s in r.json().get("Temperatures", []):
        name  = s.get("Name", "")
        state = s.get("Status", {}).get("State", "")
        if name in SENSORS and state == "Enabled":
            out[name] = s["ReadingCelsius"]
    return out


def fan_speed_for(temp, curve):
    if temp <= curve[0][0]:  return curve[0][1]
    if temp >= curve[-1][0]: return curve[-1][1]
    for i in range(len(curve) - 1):
        t0, p0 = curve[i]
        t1, p1 = curve[i + 1]
        if t0 <= temp <= t1:
            return round(p0 + (temp - t0) / (t1 - t0) * (p1 - p0))
    return 100


def set_fans(speed_pct):
    value = math.ceil(speed_pct / 100 * 255)
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(ILO_HOST, username=ILO_USER, password=ILO_PASS,
                timeout=10, look_for_keys=False, allow_agent=False)
    try:
        transport = ssh.get_transport()
        for i in range(FAN_COUNT):
            for cmd in (f"fan p {i} max {value}", f"fan p {i} min 255"):
                ch = transport.open_session()
                ch.exec_command(cmd)
                ch.recv_exit_status()
                ch.close()
    finally:
        ssh.close()


def main():
    last_speed = None
    log.info("iLO fan controller started — polling every %ds", POLL_INTERVAL)
    while True:
        try:
            curve = load_fan_curve()
            temps = get_temperatures()
            if not temps:
                log.warning("No sensor readings — leaving fans unchanged")
            else:
                max_temp   = max(temps.values())
                hot_sensor = max(temps, key=temps.get)
                speed      = fan_speed_for(max_temp, curve)

                log.info(
                    "%s  |  hottest: %s=%.0f°C  |  fans: %d%%",
                    "  ".join(f"{k.split('-', 1)[1]}={v:.0f}°C" for k, v in sorted(temps.items())),
                    hot_sensor.split("-", 1)[1], max_temp, speed,
                )

                if speed != last_speed:
                    set_fans(speed)
                    log.info("Fan speed changed to %d%%", speed)
                    last_speed = speed

        except Exception:
            log.exception("Poll failed — fans unchanged, will retry")

        time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    main()
