# Yealink ticket: T87W cannot auto-provision from servers outside the OpenVPN tunnel while the VPN is up

**Product:** SIP-T87W
**Firmware:** 185.87.0.37 (public) and 185.87.0.42 (test build from Yealink). Both behave the same.
**Comparison phone:** SIP-T57W, firmware 96.87.0.20 (works correctly)
**Tested:** 2026-11-11 and 2026-11-12

## Summary

With OpenVPN connected, the T87W **sends all auto-provisioning traffic into the VPN tunnel, regardless of the phone's routing table**. When the provisioning server's address is **the same as the OpenVPN server's endpoint address** (the `remote` in `vpn.cnf`), the phone never sends a request at all. The provisioning task then stays at `autoprovision` and ignores further triggers until it reboots.

Ordinary traffic isn't affected and follows the normal routing table:
- SIP to non-tunnel hosts
- the phone's ping and traceroute diagnostics
- the firmware download

Evidence (VPN connected; the phone's own traceroute shows non-LAN destinations going via the LAN gateway 192.168.1.1, not the tunnel):

| Provisioning destination | What happened |
|---|---|
| `10.8.0.1` (server's tunnel IP) | ✅ Request arrives through the tunnel |
| `172.31.255.1` (extra address on the server, not on any LAN; the phone has no route except its default gateway) | ✅ Request arrives **through the tunnel** (server `tun0`, from 10.8.0.2), although traceroute sends that address to 192.168.1.1 |
| `8.8.8.8` (public internet) | Request arrives **through the tunnel** at the VPN server (`tun0`, from 10.8.0.2) instead of going to the internet gateway |
| `192.168.1.243` (extra server address on the phone's LAN, **not** the VPN endpoint) | ✅ Request arrives through the tunnel |
| `192.168.1.31` (server LAN IP = **OpenVPN endpoint**) | ❌ No packet at all: no SYN on the phone (on-phone pcap) or the server; status stuck |
| `192-168-1-31.nip.io` (DNS name → the endpoint IP) | ❌ Same |

The T57W (96.87.0.20), with the same VPN server and endpoint, provisions from `192.168.1.31` normally while connected.

**Why this matters.** Remote phones normally provision from the PBX's public hostname, and that is usually the same public IP as the VPN endpoint. On 185.87.x such phones can't be re-provisioned at all while their VPN is up. And because provisioning is forced into the tunnel, a provisioning server that isn't the VPN server is only reachable if the VPN server forwards the traffic onward.

## Phone log (level 6) — the provisioning service binds to tun0

From the phone's local log during Auto Provision Now with the provisioning server at the VPN endpoint address:

```
ATP: DURL<6> [DCMN]download to file...
ATP: DURL<6> [DCMN]durl use network interface tun0.
ATP: DURL<3> [DCMN]No request was made to a valid IP.          (20 s later)
ATP: DURL<3> [DCMN]download common error, errcode:28, no out.  (curl 28 = timeout)
ATP: ATP <3> http to file failed, code = -128, msg = , retry = 2
ATP: ATP <6> Wait 5 second to next file transfer!
...  retry 3 ...
ATP: ATP <4> Download mac.boot fail
ATP: ATP <6> Upgrade from default.boot      -> same cycle again
```

The provisioning downloader (ATP/DURL) explicitly binds to `tun0` while the VPN is up. That explains why requests to non-tunnel addresses arrive through the tunnel, and why a request to the VPN endpoint's own address never leaves the phone. The "stuck" state is the retry chain: about 75 s per file (3 attempts × 20 s timeout plus 5 s waits) across the boot, common and MAC files.

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
| 11 | `http://user:pass@192.168.1.31:84` (credentials in URL) | Auto Provision Now | ❌ No connection attempt |
| 12 | `https://192.168.1.31:8443` (credentials in the username/password fields) | Auto Provision Now | ❌ No connection attempt |
| 13 | `https://user:pass@192.168.1.31:8443` (credentials in URL) | Auto Provision Now | ❌ No connection attempt |
| 14 | `https://user:pass@10.8.0.1:8443` (tunnel IP, HTTPS) | Auto Provision Now | ✅ Phone attempts the connection (SYNs seen on the tunnel); this server's firewall dropped them, so only the attempt is relevant here |

Tests 11–14 were run on 2026-11-12 after a **factory reset**: the phone was provisioned fresh, the VPN connected, and the phone was idle before each trigger and rebooted between tests. So the scheme (HTTP or HTTPS) and the way credentials are supplied make no difference. Only whether the destination is inside the tunnel matters.

**Other observations:**
- After a **successful** fetch through the tunnel (tests 1–3), the status stays `autoprovision` for about 80 s and new triggers are ignored. During that time the config's other URLs, which pointed at the LAN IP, were processed. The only request that eventually arrived from 192.168.1.246 was the firmware URL, about 75 s later.
- `static.auto_provision.attempt_expired_time` set to 10 instead of 20 makes no difference.
- Changing Settings → Auto Provision → server URL from the web UI while the phone is stuck has no effect until reboot (the value saves but isn't used).

## Ruled out: routing and MTU

- **Routing.** Neither the server nor the client profile pushes routes or `redirect-gateway` (`server 10.8.0.0 255.255.255.0`; the per-client override files are empty). With the VPN up, the phone's own diagnostics (`/api/diagnosis/start`) give:
  - **Traceroute to 192.168.1.31:** one hop, 2.5 ms.
  - **Ping:** replies, and the packets arrive on the server's **LAN** interface from 192.168.1.246, not through the tunnel.

  So the route to the server is direct and works.
- **MTU.** The failing attempts don't send a single packet (not even a TCP SYN or a DNS query), so packet size can't be the cause. 90 KB configs download fine through the tunnel.
- **On-phone packet capture** (Diagnostics → Pcap, `docs/evidence/t87w-185.87.0.37-autop-lan-ip-vpn-up.pcap`), taken while triggering Auto Provision Now with `http://192.168.1.31:84` at 16:20:55 UTC:
  - no TCP SYN and no DNS query from the phone to any host
  - the only traffic to the server is the OpenVPN transport (UDP 1194) and one ARP exchange
  - the server-side capture on the tunnel interface shows nothing either

  The auto-provisioning task never starts a request.

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

1. Is sending auto-provisioning through the VPN tunnel (ignoring the routing table) intentional on 185.87.x? Is there a parameter to make provisioning use normal routing while the VPN is up? On the T5x it does.
1a. Why is a provisioning server whose address equals the OpenVPN `remote` never contacted? Presumably the firmware avoids routing the endpoint into its own tunnel, but then it should use the normal route rather than hang.
2. If it's a bug, which firmware will fix it, and is it also in the T73/T74/T77/T85 builds that share 185.87.x?
3. Why does the provisioning task stay at `autoprovision` with no connection attempt and no timeout, instead of failing the attempt and becoming idle? This also blocks check-sync until reboot.

## Reproduction

1. Provision `static.network.vpn_enable = 1` and an `openvpn.url` tar. Wait until the OpenVPN server lists the client.
2. Set the auto-provision server URL to a non-tunnel address (LAN IP or a DNS name) and trigger Auto Provision Now, or send a check-sync NOTIFY.
3. Watch the server, e.g. `tcpdump -i any host <phone-lan-ip> or host <phone-tunnel-ip>`: no connection is made.
4. `GET /api/autop/status?noAutoJump=true` returns `"autoprovision"` until the phone is rebooted.
5. Repeat with the URL set to the server's tunnel address: provisioning succeeds.

Server access logs, packet captures and the phone's exported configuration are available on request.
