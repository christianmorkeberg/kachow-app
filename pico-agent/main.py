# Kachow home agent — MicroPython for the Raspberry Pi Pico W / Pico 2 W.
#
# What it does: it only ever calls OUT to your Kachow server. It long-polls for a command
# ("send this request to the hue bridge"), runs it against the device on your home network,
# and posts the answer back. Nothing connects into your home. Runs automatically on power-up.
#
# LED: blinking = connecting to Wi-Fi, solid = online and waiting for commands,
#      quick flicker = running a command, slow blink = a problem (it retries by itself).

import gc
import json
import socket
import time

import machine
import network

try:
    import ssl
except ImportError:  # older MicroPython
    import ussl as ssl

import config
from agent_lib import (build_request, parse_response, path_allowed, reset_due, response_complete,
                       should_feed, split_url, append_log, tail_lines)

VERSION = "2"
POLL_WAIT_S = 20          # how long the server may hold a poll open (it answers instantly on a command)
MAX_RESPONSE = 30000      # bytes of a device answer we keep (a Hue light list is a few KB)
BOOT_MS = time.ticks_ms()
STALL_MS = 150000         # the loop silent this long (stuck in DNS / TLS / a dead socket) = hung: stop feeding the watchdog
NO_SUCCESS_RESET_MS = 600000   # no poll has succeeded for 10 minutes, whatever the reason: restart
WDT_MS = 8000             # the hardware watchdog (the longest the RP2040 allows is ~8.3 s)

_beat_ms = time.ticks_ms()   # the main loop reports in here; see _feed()
_wdt = None
BLACKBOX = "blackbox.txt"   # the newest ~14 events (why it restarted, the first error of each outage) — survives a restart
_last_note_ms = -100000

led = machine.Pin("LED", machine.Pin.OUT)


def log(*args):
    print("[agent]", *args)


def note(msg, force=True):
    """Write one line to the black box. Only on events, never in a loop, so the flash is not worn out."""
    global _last_note_ms
    now = time.ticks_ms()
    if not force and time.ticks_diff(now, _last_note_ms) < 60000:
        return
    _last_note_ms = now
    try:
        try:
            with open(BLACKBOX) as f:
                old = f.read()
        except OSError:
            old = ""
        line = "up=%ds %s" % (time.ticks_diff(now, BOOT_MS) // 1000, msg)
        with open(BLACKBOX, "w") as f:
            f.write(append_log(old, line))
    except Exception as e:
        print("[agent] black box failed:", e)


def black_box_tail():
    try:
        with open(BLACKBOX) as f:
            return tail_lines(f.read(), 6)
    except Exception:
        return []


def restart(reason):
    note("RESTART: " + reason)
    time.sleep_ms(200)
    machine.reset()


def beat():
    """The main loop is alive and making progress."""
    global _beat_ms
    _beat_ms = time.ticks_ms()


def _feed(_t):
    # Runs from a timer, so it keeps running while the loop sits in a blocking call — but only FEEDS the watchdog while the
    # loop has reported in recently. A real hang (this is what left the Pico dead for 12 h) stops the feeding, and the
    # hardware watchdog restarts the Pico 8 seconds later.
    if _wdt is not None and should_feed(time.ticks_diff(time.ticks_ms(), _beat_ms), STALL_MS):
        _wdt.feed()


def start_watchdog():
    global _wdt
    if not getattr(config, "WATCHDOG", True):
        log("watchdog is off (WATCHDOG = False in config.py)")
        return
    try:
        _wdt = machine.WDT(timeout=WDT_MS)
        machine.Timer(mode=machine.Timer.PERIODIC, period=2000, callback=_feed)
        log("watchdog on: restarts the Pico if the program hangs")
    except Exception as e:     # an old firmware without a periodic Timer: better no watchdog than a reboot loop
        _wdt = None
        log("watchdog not started:", e)


def reset_cause_name():
    try:
        c = machine.reset_cause()
        return {machine.PWRON_RESET: "power-on", machine.WDT_RESET: "watchdog"}.get(c, str(c))
    except Exception:
        return "?"


def blink(times, on_ms=100, off_ms=100):
    for _ in range(times):
        led.on()
        time.sleep_ms(on_ms)
        led.off()
        time.sleep_ms(off_ms)


# --------------------------------------------------------------------------- Wi-Fi

def connect_wifi():
    wlan = network.WLAN(network.STA_IF)
    wlan.active(True)
    try:
        wlan.config(pm=network.WLAN.PM_NONE)   # no power-saving: much snappier replies
    except Exception:
        pass
    if wlan.isconnected():
        return wlan
    log("connecting to Wi-Fi", config.WIFI_SSID)
    wlan.connect(config.WIFI_SSID, config.WIFI_PASSWORD)
    for _ in range(40):
        beat()
        if wlan.isconnected():
            log("Wi-Fi up, IP", wlan.ifconfig()[0])
            led.on()
            return wlan
        blink(1, 250, 250)
    raise OSError("Wi-Fi did not connect (wrong name/password, or 5 GHz only?)")


# --------------------------------------------------------------------------- HTTP

def _wrap_tls(sock, host):
    # No certificate checking: the Hue bridge's certificate is self-signed, and MicroPython
    # ships no CA list. The Kachow token travels inside TLS, but a determined attacker already
    # on your home network could impersonate the server — acceptable for a home LAN; see README.
    try:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ctx.verify_mode = ssl.CERT_NONE
        return ctx.wrap_socket(sock, server_hostname=host)
    except AttributeError:
        return ssl.wrap_socket(sock, server_hostname=host)


def http(method, url, headers=None, body=None, timeout=15):
    https, host, port, path = split_url(url)
    addr = socket.getaddrinfo(host, port)[0][-1]
    s = socket.socket()
    s.settimeout(timeout)
    try:
        s.connect(addr)
        if https:
            s = _wrap_tls(s, host)
        s.write(build_request(method, host, path, headers, body))
        buf = b""
        while len(buf) < MAX_RESPONSE + 4096:
            try:
                chunk = s.read(1024)
            except OSError:
                if buf:          # peer closed without a clean TLS goodbye — we have what it sent
                    break
                raise
            if not chunk:
                break
            buf += chunk
            if response_complete(buf):
                break
        return parse_response(buf)
    finally:
        try:
            s.close()
        except Exception:
            pass


# --------------------------------------------------------------------------- commands

def run_command(cmd, wlan):
    """-> (http_status or None, body text or None, error text or None)"""
    device = cmd.get("device", "")
    method = cmd.get("method", "GET")
    path = cmd.get("path", "/")

    if device == "pico":      # the agent itself — a connection test, no LAN call
        if path.split("?")[0] == "/ping":
            return 200, json.dumps({
                "ok": True, "version": VERSION, "ip": wlan.ifconfig()[0],
                "uptime_s": time.ticks_diff(time.ticks_ms(), BOOT_MS) // 1000,
                "free_mem": gc.mem_free(), "last_restart": reset_cause_name(),
                "black_box": black_box_tail(),
            }), None
        return None, None, "the pico only supports GET /ping"

    dev = config.DEVICES.get(device)
    if dev is None:
        return None, None, "no device called '%s' is configured on this Pico" % device
    if method not in dev.get("methods", ["GET", "PUT", "POST"]):
        return None, None, "method %s is not allowed for '%s'" % (method, device)
    if not path_allowed(dev.get("allow", []), path):
        return None, None, "path %s is not allowed for '%s' on this Pico" % (path, device)

    body = json.dumps(cmd["body"]) if cmd.get("body") is not None else None
    status, _headers, data = http(method, dev["base"] + path, dev.get("headers"), body, timeout=10)
    try:
        text = data.decode()
    except Exception:
        text = None
    if text is None:
        return status, None, "the device answered with data that is not text"
    return status, text, None


def post_result(cmd_id, status, body, error):
    payload = json.dumps({"id": cmd_id, "status": status, "body": body, "error": error})
    url = "%s/api/agent.php?t=%s&action=result" % (config.SERVER, config.TOKEN)
    for attempt in range(2):
        try:
            st, _h, _b = http("POST", url, None, payload, timeout=15)
            if st == 200:
                return
        except Exception as e:
            log("result post failed:", e)
        time.sleep(1)


def handle(cmd, wlan):
    log("command", cmd.get("id"), cmd.get("method"), cmd.get("device"), cmd.get("path"))
    blink(2, 40, 40)
    try:
        status, body, error = run_command(cmd, wlan)
    except Exception as e:
        status, body, error = None, None, "agent error: %s" % e
    post_result(cmd["id"], status, body, error)
    led.on()
    gc.collect()


def poll():
    url = "%s/api/agent.php?t=%s&action=poll&wait=%d" % (config.SERVER, config.TOKEN, POLL_WAIT_S)
    status, _h, body = http("GET", url, None, None, timeout=POLL_WAIT_S + 15)
    if status == 401:
        raise OSError("the server rejected the token (401) — check TOKEN in config.py")
    if status != 200:
        raise OSError("poll answered HTTP %d" % status)
    return json.loads(body).get("command")


# --------------------------------------------------------------------------- main loop

def restart_wifi(wlan):
    """A half-dead Wi-Fi link (connected on paper, nothing flows) is cured by switching the radio off and on."""
    log("restarting the Wi-Fi radio")
    try:
        wlan.disconnect()
    except Exception:
        pass
    try:
        wlan.active(False)
        time.sleep(1)
        wlan.active(True)
    except Exception as e:
        log("radio restart failed:", e)


def main():
    # A 5-second window at power-up: press Stop in Thonny now if you need to edit files. Once the
    # polling loop starts, it keeps the Pico busy and Thonny may take a while to get its attention.
    # (The watchdog starts only AFTER this window — it cannot be switched off again once running.)
    log("starting in 5 s — press Stop in Thonny now if you need to edit files")
    for _ in range(5):
        blink(1, 500, 500)
    log("last restart:", reset_cause_name())
    note("boot (%s), v%s" % (reset_cause_name(), VERSION))
    start_watchdog()
    beat()
    fails = 0
    wlan = None
    last_ok = time.ticks_ms()
    while True:
        beat()
        try:
            if wlan is None or not wlan.isconnected():
                wlan = connect_wifi()
            cmd = poll()
            fails = 0
            last_ok = time.ticks_ms()
            beat()
            led.on()
            if cmd:
                handle(cmd, wlan)
                beat()
        except Exception as e:
            fails += 1
            log("problem (%d):" % fails, e)
            blink(3, 600, 300)
            if fails == 1:        # the first error of an outage says what went wrong; the rest are noise
                note("first error: %s" % e, force=False)
            if reset_due(time.ticks_diff(time.ticks_ms(), last_ok), NO_SUCCESS_RESET_MS):
                log("no successful poll for 10 minutes — restarting")
                restart("no successful poll for 10 min, last error: %s" % e)
            if fails in (4, 8) and wlan is not None:
                note("%d failures in a row, restarting the Wi-Fi radio" % fails)
                restart_wifi(wlan)
                wlan = None
            if fails >= 15:       # something is stuck — a clean restart fixes most things
                restart("15 failures in a row, last error: %s" % e)
            beat()
            time.sleep(min(30, 2 * fails))


main()
