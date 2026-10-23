# Copy this file to config.py (on the Pico) and fill it in. config.py is git-ignored:
# it holds your Wi-Fi password, your Kachow token and your Hue key, so NEVER commit it.

# Your home Wi-Fi. The Pico only does 2.4 GHz — not 5 GHz-only networks.
WIFI_SSID = "your-wifi-name"
WIFI_PASSWORD = "your-wifi-password"

# Where the app lives, and your private agent token. Ask the assistant:
# "set up my home agent" — it shows both.
SERVER = "https://assistant.kachow.dk"
TOKEN = "paste-your-agent-token-here"

# The devices on your home network this Pico is allowed to talk to, by alias. The assistant
# only ever says "send this to hue" — the address, the key and the allowed paths live HERE,
# so the server can never point the Pico at anything you didn't list.
DEVICES = {
    "hue": {
        # Your Hue bridge's IP address (give it a fixed IP in your router first).
        "base": "https://192.168.1.50",
        # Created once by pressing the bridge's link button (see README).
        "headers": {"hue-application-key": "paste-your-hue-key-here"},
        # Only these paths (and anything under them) may be called.
        "allow": [
            "/clip/v2/resource/light",
            "/clip/v2/resource/grouped_light",
            "/clip/v2/resource/room",
            "/clip/v2/resource/zone",
            "/clip/v2/resource/scene",
            "/clip/v2/resource/device",
        ],
        "methods": ["GET", "PUT"],
    },
    # Radiator thermostats: a Shelly BLU gateway on your network (the valves talk Bluetooth to it). Plain http on your LAN.
    # Only the valve calls are allowed. NEVER allow all of /rpc: the gateway also has Script.* (runs code on it), HTTP.* (makes
    # it call other hosts), Shelly.FactoryReset / SetAuth / Reboot / Update, OTA.*, Wifi.SetConfig, Webhook.* ... (see its
    # /rpc/Shelly.ListMethods). Pairing, removing and flashing valves stay in the Shelly app.
    "shelly": {
        "base": "http://192.168.1.60",
        "allow": [
            "/rpc/BluTrv.Call",                  # set the target, calibrate ... (TRV.* methods, sent as a POST body)
            "/rpc/BluTrv.GetRemoteStatus",       # current temperature, target, valve position
            "/rpc/BluTrv.GetRemoteConfig",
            "/rpc/BluTrv.GetRemoteDeviceInfo",
            "/rpc/BluTrv.GetStatus",             # battery, signal, paired
            "/rpc/BluTrv.GetConfig",
            "/rpc/BluTrv.CheckForUpdates",
            "/rpc/Shelly.GetDeviceInfo",
        ],
        "methods": ["GET", "POST"],
    },
}

# The hardware watchdog restarts the Pico by itself if the program ever hangs (no more "off for 12 hours").
# It cannot be switched off once running, so while you are editing files in Thonny set this to False and restart;
# the 5-second window right after power-up (before the watchdog starts) is also enough to press Stop.
WATCHDOG = True
