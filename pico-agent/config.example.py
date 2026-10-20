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
    # Radiator thermostats: a Shelly BLU gateway on your network (the valves talk Bluetooth to it). Plain http on your LAN;
    # only the three valve calls are allowed — nothing else on the gateway can be reached through the Pico.
    "shelly": {
        "base": "http://192.168.1.60",
        "allow": [
            "/rpc/BluTrv.Call",
            "/rpc/BluTrv.GetRemoteStatus",
            "/rpc/BluTrv.GetStatus",
        ],
        "methods": ["GET", "POST"],
    },
}
