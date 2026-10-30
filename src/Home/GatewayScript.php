<?php

declare(strict_types=1);

namespace App\Home;

/**
 * The script that goes on the Shelly BLU gateway (its web page → Scripts): every time a radiator valve reports (about every 5 minutes) the gateway notices the
 * status change of `blutrv:<id>`, reads the valve's target / temperature / valve position with BluTrv.GetRemoteStatus and calls api/shelly-hook.php with them — so
 * the server hears the news at once instead of asking the valves through the Pico. mJS (Shelly's JavaScript): no arrow functions or template strings.
 * The token in the script is the user's private 'shelly' token: it only lets the holder post valve readings.
 */
final class GatewayScript
{
    public const SCOPE = 'shelly';

    public static function code(string $baseUrl, string $token): string
    {
        $hook = rtrim($baseUrl, '/') . '/api/shelly-hook.php?t=' . $token;

        return <<<JS
// Kachow: tells the server when a radiator valve has reported. Private: it contains your token — do not share or paste it anywhere public.
let HOOK = "{$hook}";
let SSL = "ca.pem";   // if the console says the TLS check failed, change this to "*" (no certificate check)
let last = {};

function send(id, rssi, lastTs, tries) {
  Shelly.call("BluTrv.GetRemoteStatus", { id: id }, function (res, code) {
    if (code !== 0 || !res || !res.status || !res.status.trv) {
      print("kachow: no remote status for", id, code);
      return;
    }
    // The gateway may not have stored the new report yet: if the status is older than the report that woke us, wait a little and read again (3 tries).
    if (lastTs !== undefined && res.ts !== undefined && res.ts + 5 < lastTs && tries < 3) {
      Timer.set(3000, false, function () { send(id, rssi, lastTs, tries + 1); });
      return;
    }
    let trv = res.status.trv;
    let url = HOOK + "&id=" + id + "&target=" + trv.target_C + "&cur=" + trv.current_C + "&pos=" + trv.pos + "&ts=" + res.ts;
    if (rssi !== undefined) { url = url + "&rssi=" + rssi; }
    HTTP.GET({ url: url, timeout: 15, ssl_ca: SSL }, function (r, ec, em) {
      if (ec !== 0 || !r) { print("kachow: send failed", id, ec, em); }
    });
  });
}

Shelly.addStatusHandler(function (e) {
  if (!e || !e.component || e.component.indexOf("blutrv:") !== 0 || !e.delta) { return; }
  let id = e.delta.id !== undefined ? e.delta.id : e.id;
  if (id === undefined || e.delta.packet_id === undefined || last[id] === e.delta.packet_id) { return; }   // one call per report
  last[id] = e.delta.packet_id;
  let rssi = e.delta.rssi;
  let lastTs = e.delta.last_updated_ts;
  Timer.set(1500, false, function () { send(id, rssi, lastTs, 0); });   // a moment for the gateway to store the new status
});
print("kachow: gateway script running");
JS;
    }
}
