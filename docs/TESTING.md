# Testing

## Automated

```sh
php tests/run.php            # 20 tests; needs PHP 8.1+ with pdo_sqlite and gd
php tests/run.php server     # tests whose name contains "server"
```

The suite runs the real `Store`, `ConfigBuilder`, `ProvisionServer`, `Service`
and `Yealink` classes against an in-memory SQLite database. FreePBX, the VPN
and the notifier are replaced by fakes. It covers:

- MAC, label, raw-key and CIDR helpers
- the tar writer (checked with `tar -tvf`)
- Asterisk pattern matching for BLF hints
- model detection from the User-Agent
- device validation
- rendering: labels, `%NULL%` clearing, `{ext}`, time zones, passwords, `custom.protect`
- precedence: raw keys over defaults, secrets over raw keys, template inheritance
- bootstrap → claim → per-device credential flow, and re-arming
- User-Agent MAC checks, the allow-list, path traversal, discovery and auto-adopt
- VPN tar authorisation and remotes
- change detection and one-shot notifies
- wallpaper resizing and asset validation
- CSV round trip
- the RSA key encoding used by the phone web-API client

## Hardware results (2026-11-12)

Lab: FreePBX 17.0.33 on Debian 12 with System Admin Pro. Phone: Yealink T87W
at 192.168.1.246, extension 212.

| Feature | Result |
|---|---|
| HTTPS provisioning (certman self-signed cert, port 8443) | ✅ The T87W accepted the certificate with factory trust settings |
| Bootstrap credential → per-device credential | ✅ First fetch used the bootstrap credential, the next used the phone's own. Bootstrap refused after that |
| User-Agent MAC check | ✅ 403 on a mismatch |
| Rendered config: account, time zone, passwords | ✅ Registered, correct local time |
| 84 line keys; unused keys cleared with `%NULL%` | ✅ Keys 85+ are rejected by the phone (probed) |
| BLF to users, labels from display names | ✅ |
| Feature-code BLFs (time condition `*271`, call flow `*281`, follow-me `*21{ext}`, DND `*76{ext}`), park slot `71` | ✅ The lights show live state |
| `custom.protect = 0` (server wins over phone-side edits) | ✅ Old labels set on the phone were replaced |
| Re-sync with AMI `PJSIPNotify` (`Event` header inline, no notify config file) | ✅ Phone fetched within 1 s |
| Rename a user → `fwconsole reload` → the phone's button label updates | ✅ No manual step |
| Wallpaper (resized to 800×480), custom ringtone | ✅ |
| Firmware upgrade/downgrade (185.87.0.42 → 185.87.0.37) through OpenProv | ✅ About 2 minutes. After flashing, the phone reads only the header (1.8 MB) and stops, so there's no loop |
| System Admin VPN tar (`openvpn.url`), registration through the tunnel | ✅ |
| Updating a VPN phone with check-sync | ❌ on the T87W (185.87.x), a Yealink firmware issue, see [YEALINK-VPN-ISSUE.md](YEALINK-VPN-ISSUE.md). ✅ on the T57W (96.87.0.20): check-sync and provision-now both work with the VPN up |
| T57W (96.87.0.20) with a self-signed certificate | ❌ The phone rejects it (TLS alert) because "Only Accept Trusted Certificates" is on by default. It works once the certificate is in the phone's trusted list. **Production needs a publicly trusted certificate (e.g. Let's Encrypt via certman), or the CA pushed to the phones, before the first HTTPS fetch** |
| Provisioning MAC vs. ARP MAC | Wi-Fi models (T87W, T57W) answer ARP with a different MAC than the one they provision with (`80:5e:c0:f4:e6:e8` vs `80:5e:c0:d6:7c:5f`). Use the MAC from the label, the User-Agent or OpenProv's Activity tab |
| Updating a VPN phone with a web-API reboot through the tunnel (10.8.0.2) | ✅ The phone rebooted. Boot-time provisioning races the VPN coming up (Yealink issue) |
| GraphQL reads and the `openprovAction` mutation with asterisk-server's OAuth client | ✅ |
| GUI: list, edit, save (Playwright) | ✅ |

## Manual checks worth repeating on a new firmware or model

1. Count line keys: import `linekey.N.type = 13` for large N and check which ones survive a config export.
2. Find the common config file: check the `y0000000000NN.cfg` the phone requests (OpenProv logs it under Activity).
3. Confirm check-sync with the VPN on and off (`fwconsole openprov resync devices='["<mac>"]'`).
4. Confirm `custom.protect = 0` overrides labels that were set on the phone.
