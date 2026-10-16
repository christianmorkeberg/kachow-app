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
from agent_lib import (build_request, parse_response, path_allowed, response_complete,
                       split_url)

VERSION = "1"
POLL_WAIT_S = 20          # how long the server may hold a poll open (it answers instantly on a command)
MAX_RESPONSE = 30000      # bytes of a device answer we keep (a Hue light list is a few KB)
BOOT_MS = time.ticks_ms()

led = machine.Pin("LED", machine.Pin.OUT)


def log(*args):
    print("[agent]", *args)


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
                "free_mem": gc.mem_free(),
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

def main():
    fails = 0
    wlan = None
    while True:
        try:
            if wlan is None or not wlan.isconnected():
                wlan = connect_wifi()
            cmd = poll()
            fails = 0
            led.on()
            if cmd:
                handle(cmd, wlan)
        except Exception as e:
            fails += 1
            log("problem (%d):" % fails, e)
            blink(3, 600, 300)
            if fails >= 15:       # something is stuck — a clean restart fixes most things
                machine.reset()
            time.sleep(min(30, 2 * fails))


main()
