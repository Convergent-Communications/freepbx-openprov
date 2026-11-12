# Yealink T87W: auto-provisioning never runs while OpenVPN is up

A bug report, ready to send to Yealink. Tested 2026-11-12 on a lab FreePBX 17
box running System Admin Pro's OpenVPN server.

## Environment

- **Phone:** SIP-T87W, MAC c4:fc:22:1d:d6:37.
- **Firmware:** 185.87.0.42 (a test build from Yealink) and 185.87.0.37 (public). Both behave the same.
- **Provisioning server:** HTTPS with Basic auth on the LAN (192.168.1.31:8443), and plain HTTP (EPM, port 84).
- **OpenVPN:** OpenVPN 2.6 (FreePBX System Admin). Profile delivered with `openvpn.url`. `static.network.vpn_enable = 1`. UDP 1194, `comp-lzo`, AES-256-GCM. The tunnel comes up fine and SIP registers through it.

## Symptom

With the VPN disabled, every trigger works. The phone fetches `<mac>.cfg` within about a second of:

- a SIP NOTIFY `Event: check-sync;reboot=false` (the phone answers 200 OK)
- the web UI's "Auto Provision Now" (`POST /api/autop/now`)
- the periodic timer (`static.auto_provision.repeat.*`)

With the VPN enabled and the tunnel up, none of these work:

- **check-sync NOTIFY:** the phone answers 200 OK, but no HTTP request is ever sent. A packet capture on the server, on both the LAN and tun interfaces, shows no TCP SYN at all, only ARP.
- **check-sync;reboot=true:** answered 200 OK, and the phone does not reboot.
- **Auto Provision Now:** `GET /api/autop/status` reports `autoprovision` indefinitely. The phone stays in that state until it reboots, and while it's stuck, later NOTIFYs and some config imports are ignored.
- **Periodic repeat (2 minutes):** no requests for at least 6 minutes.
- **Power-on provisioning:** works only sometimes. It seems to depend on whether it runs before the tunnel comes up. One reboot fetched the config, the VPN tar and the firmware. Another reboot (the VPN reconnected 35 s after the reboot command) made no request and left the status at `autoprovision`.
- **Server URL on the tunnel address:** the same thing happens when the provisioning URL points at the server's tunnel address (`https://10.8.0.1:8443`). No request is made, and a boot-time fetch fails because the tunnel isn't up yet.
- **Attempt expiry:** changing `static.auto_provision.attempt_expired_time` (`AutopRetryDelay`) from 20 to 10 makes no difference.

## Expected

Auto provisioning should behave the same with OpenVPN enabled. In particular:

- check-sync should trigger a fetch over whichever route reaches the server (LAN or tunnel).
- `reboot=true` should reboot the phone.

## Impact and workaround

A phone that uses the VPN can't pick up configuration changes without a reboot. OpenProv works around this by rebooting the phone through its web API (`POST /api/system/reboot`), but only when the line is idle. That is disruptive compared with check-sync.

## Reproduction

1. Provision `static.network.vpn_enable = 1` and an `openvpn.url` tar. Wait until the tunnel is up (the server lists the client).
2. `asterisk -rx 'pjsip send notify <type with Event=>check-sync\;reboot=false> endpoint <ext>'`. The phone replies 200 OK.
3. Watch the provisioning server log: nothing arrives. `GET /api/autop/status?noAutoJump=true` returns `"autoprovision"` from then on.
