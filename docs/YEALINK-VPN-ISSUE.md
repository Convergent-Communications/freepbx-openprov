# Yealink ticket: T87W cannot auto-provision from servers outside the OpenVPN tunnel while the VPN is up

**Product:** SIP-T87W
**Firmware:** 185.87.0.37 (public) and 185.87.0.42 (test build from Yealink). Both behave the same.
**Comparison phone:** SIP-T57W, firmware 96.87.0.20 (works correctly)
**Tested:** 2026-11-11 and 2026-11-12

## Summary

With OpenVPN enabled and connected, the T87W only completes auto provisioning when the provisioning URL points at an address **inside** the VPN tunnel. When the URL points at any other address — the provisioning server's LAN IP or a DNS hostname — the phone never opens a TCP connection. Its auto-provisioning status then stays at `autoprovision` and it ignores further triggers until it reboots.

This affects every trigger:
- check-sync NOTIFY
- Auto Provision Now
- the Repeatedly timer
- Power On, once the tunnel is up

The T57W, using the same server, the same OpenVPN server and the same configuration, provisions normally with the VPN up, including from the LAN address.

We need remote phones to keep provisioning from our server's **public hostname**, resolved by DNS when provisioning runs, while their VPN carries SIP. Then changing the VPN configuration, or moving a customer to another server, can't leave phones unable to fetch configuration.

## Environment

- **Provisioning server:** FreePBX 17, HTTP on port 84 (Basic auth) and HTTPS on 8443 (Basic auth).
- **OpenVPN server:** FreePBX System Admin, OpenVPN 2.6.14, UDP 1194, `topology subnet`, tunnel 10.8.0.0/24 (server 10.8.0.1, phone 10.8.0.2), `comp-lzo`, AES-256-GCM. No `redirect-gateway`: only the tunnel subnet is routed through the VPN.
- **VPN delivery:** a tar (`vpn.cnf` + `keys/`) via `openvpn.url`, with `static.network.vpn_enable = 1`. The tunnel connects and SIP registers through it.
- **Network:** phone and server on the same LAN (phone 192.168.1.246, server 192.168.1.31).

## Results (T87W, VPN connected, phone idle before each test)

| # | Provisioning URL | Trigger | Result |
|---|---|---|---|
| 1 | `http://10.8.0.1:84` (tunnel IP) | Auto Provision Now (web API `/api/autop/now`) | ✅ Fetched config; requests came from 10.8.0.2 |
| 2 | `http://10.8.0.1:84` | SIP NOTIFY `Event: check-sync;reboot=false` | ✅ Fetched config |
| 3 | `http://10.8.0.1:84` | Power On (reboot) | ✅ Fetched config after the tunnel came up |
| 4 | `http://192.168.1.31:84` (server LAN IP) | Auto Provision Now | ❌ No TCP SYN on any server interface for 3+ minutes. Status stays `autoprovision` until reboot |
| 5 | `http://192-168-1-31.nip.io:84` (DNS name → 192.168.1.31) | Auto Provision Now | ❌ Same as 4: no connection, status stuck at `autoprovision` |
| 6 | `http://192.168.1.31:84` | check-sync NOTIFY | ❌ Phone answers `200 OK` but never fetches. `reboot=true` is ignored as well |
| 7 | `http://192.168.1.31:84` | Repeatedly (2 min) | ❌ No requests for 6+ minutes |
| 8 | `http://192.168.1.31:84` | Power On | ⚠️ Works only if provisioning runs before the tunnel connects; otherwise no request |
| 9 | `https://192.168.1.31:8443` | any | ❌ Same as 4 |
| 10 | Any URL, VPN **disabled** | any trigger | ✅ Works (fetch within about 1 s of check-sync) |

**Other observations:**
- After a **successful** fetch through the tunnel (tests 1–3), the status stays `autoprovision` for about 80 s and new triggers are ignored. During that time the config's other URLs, which pointed at the LAN IP, were processed. The only request that eventually arrived from 192.168.1.246 was the firmware URL, about 75 s later.
- `static.auto_provision.attempt_expired_time` set to 10 instead of 20 makes no difference.
- Changing Settings → Auto Provision → server URL from the web UI while the phone is stuck has no effect until reboot (the value saves but isn't used).

## Comparison: SIP-T57W 96.87.0.20

Same OpenProv/FreePBX server, same System Admin OpenVPN server (a different client certificate), VPN connected (10.8.0.3), provisioning URL `https://192.168.1.31:8443` (LAN IP, not the tunnel):
- check-sync NOTIFY: fetched within about 1 s (repeated twice).
- Auto Provision Now: fetched. The status returned to `idle`.

## Expected behaviour

While OpenVPN is connected, auto provisioning should reach the configured server by whatever route the phone's routing table gives it:
- the tunnel for tunnel addresses
- the LAN or default gateway for everything else

That is what the T57W does, and what the T87W does for its firmware download.

## Questions for Yealink

1. Is restricting provisioning to the VPN tunnel intentional on 185.87.x? If so, is there a parameter to let provisioning use the normal network while the VPN is up?
2. If it's a bug, which firmware will fix it, and is it also in the T73/T74/T77/T85 builds that share 185.87.x?
3. Why does the provisioning task stay at `autoprovision` with no connection attempt and no timeout, instead of failing the attempt and becoming idle? This also blocks check-sync until reboot.

## Reproduction

1. Provision `static.network.vpn_enable = 1` and an `openvpn.url` tar. Wait until the OpenVPN server lists the client.
2. Set the auto-provision server URL to a non-tunnel address (LAN IP or a DNS name) and trigger Auto Provision Now, or send a check-sync NOTIFY.
3. Watch the server, e.g. `tcpdump -i any host <phone-lan-ip> or host <phone-tunnel-ip>`: no connection is made.
4. `GET /api/autop/status?noAutoJump=true` returns `"autoprovision"` until the phone is rebooted.
5. Repeat with the URL set to the server's tunnel address: provisioning succeeds.

Server access logs, packet captures and the phone's exported configuration are available on request.
