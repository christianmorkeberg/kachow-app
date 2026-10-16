# Kachow home agent (Raspberry Pi Pico W / Pico 2 W)

A tiny always-on device at home. It only ever calls **out** to your Kachow server, picks up
commands ("turn the living room light on"), runs them against the Hue bridge on your home
network, and sends the answer back. Nothing connects into your home — no router changes.

## What you need
- The Pico 2 W and a **data** USB cable (some cables only charge — if the computer doesn't see the Pico, swap the cable)
- A computer, your home Wi-Fi (**2.4 GHz** — the Pico can't use 5 GHz), and your Hue bridge

## 1. Get the Hue key and bridge address (do this from a computer on the home Wi-Fi)
1. Find the bridge IP: open `https://discovery.meethue.com` — note the `internalipaddress`.
   Give the bridge a fixed IP in your router (a "DHCP reservation") so it never changes.
2. Press the round **link button** on top of the bridge, then within 30 seconds run (MobaXterm's
   local terminal works well for this):
   ```
   curl -k -X POST https://<bridge-ip>/api -H "Content-Type: application/json" -d '{"devicetype":"kachow#pico"}'
   ```
   The answer contains `"username":"…"` — that is your **Hue key**. If it says "link button not pressed", press it and retry.
   (`-k` is needed because the bridge uses a self-signed certificate.)

## 2. Put MicroPython on the Pico (once)
1. Download the MicroPython `.uf2` file for **Raspberry Pi Pico 2 W** from `micropython.org/download/RPI_PICO2_W/`.
2. Unplug the Pico. **Hold the white BOOTSEL button**, plug it into the computer, then let go.
   A drive called `RP2350` appears.
3. Drag the `.uf2` file onto that drive. The Pico restarts by itself and the drive disappears. Done.

## 3. Install Thonny (the editor for the Pico)
1. Install Thonny from `thonny.org`.
2. Bottom-right corner: choose **MicroPython (Raspberry Pi Pico)**. The "Shell" panel should show a `>>>` prompt.

## 4. Fill in `config.py`
1. Copy `config.example.py` to a new file named `config.py` and fill in: Wi-Fi name + password, `SERVER`,
   `TOKEN` (ask the assistant: *"set up my home agent"* — it shows the server address and your token),
   and under `DEVICES["hue"]` the bridge IP and the Hue key from step 1.
2. `config.py` holds secrets — it is git-ignored. Never commit or share it.

## 5. Copy the files to the Pico
In Thonny: **View → Files**. In the top ("This computer") panel find this folder, then right-click each of
`agent_lib.py`, `config.py` and `main.py` → **Upload to /**. (`main.py` runs automatically every time the Pico gets power.)

Press the stop/restart button, or unplug and replug the Pico. Watch the Shell: you should see
`Wi-Fi up, IP …` and then it quietly waits. The onboard LED is **solid** when it is online.

## 6. Test
Ask the assistant *"is my home agent online?"* and then *"ping the pico"*. Then try *"list my Hue lights"*.

## If something is wrong
- LED blinking steadily: it can't join Wi-Fi — check name/password and that it's a 2.4 GHz network.
- Slow blink + `401` in the Shell: the `TOKEN` is wrong or was rotated — ask the assistant for it again.
- Assistant says the agent is offline: no power, or Wi-Fi down. Replug it.
- To change `config.py` later: connect in Thonny, edit it on the Pico (File → Open → Raspberry Pi Pico), save, restart.

## Safety notes
- The assistant can only reach devices **you list in `config.py`**, only **paths you allow**, and only the
  **methods you allow** (GET/PUT for Hue by default). The server cannot change that.
- TLS certificates are not verified (the bridge's is self-signed and MicroPython ships no CA list). The token
  is still sent over HTTPS, but someone already on your home network could impersonate the server. Fine for a home LAN.
- If the token leaks, ask the assistant to rotate it, then update `TOKEN` on the Pico.

## Developer note
`agent_lib.py` holds the logic that doesn't touch hardware; test it on a normal computer with
`python pico-agent/test_agent_lib.py`. The server side is `src/Data/AgentCommands.php`, `api/agent.php`
(kachow-web) and the tools `home_device_call` / `get_home_agent_setup`.
