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
| HTTPS provisioning (certman self-signed cert, port 8443; 0.1.x vhost, replaced by `/openprov/` on 443 in 0.2.0) | ✅ The T87W accepted the certificate with factory trust settings |
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
| Updating a VPN phone with check-sync | T87W (185.87.x): ❌ when the provisioning URL is the LAN IP or a DNS name, ✅ when it is the tunnel IP (10.8.0.1). See [YEALINK-VPN-ISSUE.md](YEALINK-VPN-ISSUE.md). T57W (96.87.0.20): ✅ with the LAN IP, VPN up |
| T57W (96.87.0.20) with a self-signed certificate | ❌ The phone rejects it (TLS alert) because "Only Accept Trusted Certificates" is on by default. It works once the certificate is in the phone's trusted list. **Production needs a publicly trusted certificate (e.g. Let's Encrypt via certman), or the CA pushed to the phones, before the first HTTPS fetch** |
| Provisioning MAC vs. ARP MAC | Wi-Fi models (T87W, T57W) answer ARP with a different MAC than the one they provision with (`80:5e:c0:a1:b2:d9` vs `80:5e:c0:a1:b2:c4`). Use the MAC from the label, the User-Agent or OpenProv's Activity tab |
| T87W VPN bring-up from factory reset (2026-11-12) | ✅ Bootstrap over the LAN → claimed → VPN profile over the LAN → tunnel up with no reboot → re-provisioned from 10.8.0.2 about 4 s later → SIP registered via the tunnel. Needed the lab's self-signed cert uploaded to the phone's Trusted Certificates first: a reset T87W only accepts trusted certs, so the TLS handshake failed silently |
| T87W check-sync with the VPN up, provisioning URL = tunnel (OpenProv default for the model) | ✅ All files fetched through the tunnel within 1–2 s; status back to `idle` |
| Updating a VPN phone with a web-API reboot through the tunnel (10.8.0.2) | ✅ The phone rebooted. Boot-time provisioning races the VPN coming up (Yealink issue) |
| FreePBX firewall | ⚠️ With the FreePBX firewall on, OpenProv's port (8443) must be added as a custom service (Connectivity → Firewall → Services → Custom Services) for every zone phones provision from. On the lab, tun0 (VPN) is in the Internal zone, which allowed EPM's port 84 but dropped 8443. The LAN worked only because it's trusted. 0.1.x registered it automatically. Since 0.2.0 phones use FreePBX's web port, covered by the firewall's Web Management service, and the upgrade removes the custom service |
| GraphQL reads and the `openprovAction` mutation with asterisk-server's OAuth client | ✅ |
| GUI: list, edit, save (Playwright) | ✅ |

For the 0.2.0 move from the 8443 vhost to `/openprov/` (incident 2026-11-15), see the test plan in [UPGRADE-0.2.md](UPGRADE-0.2.md).

## Manual checks worth repeating on a new firmware or model

1. Count line keys: import `linekey.N.type = 13` for large N and check which ones survive a config export.
2. Find the common config file: check the `y0000000000NN.cfg` the phone requests (OpenProv logs it under Activity).
3. Confirm check-sync with the VPN on and off (`fwconsole openprov resync devices='["<mac>"]'`).
4. Confirm `custom.protect = 0` overrides labels that were set on the phone.

## T87W (185.87.0.37) provisioning-key findings, 2026-11-12

Verified through OpenProv over the VPN, by reading the phone's resource lists (`/api/resource/list?type=ringtone|wallpaper`) and its config export. The export lists only non-default values; unknown keys are dropped.

- **Several `ringtone.url` lines in one config** all download in one pass. Raw keys keep repeated keys (stored as `key#2`).
- **Ringtone numbering:**
  - Custom ringtones are numbered after the built-ins. The T87W has 15 built-ins (Ring1–13, Silent, Splash), so custom ones start at **16**. On a T57W they start at 11.
  - They are sorted **alphabetically by file name**, not by order in the config: op8-alpha was downloaded first but listed after op7-zulu.
  - So adding a file can shift every number.
- **Distinctive ring:** `distinctive_ring_tones.alert_info.N.ringer` accepts a number or a file reference (`Config:<file>.wav`). The phone's web UI writes the file reference. Use the file reference.
- **Re-sending a ringtone** the phone already has doesn't duplicate it. Ringtones from earlier configs stay on the phone.
- **`ringtone.delete = http://localhost/all`** deletes all custom ringtones. Downloads in the same config still happen afterwards.
- **`wallpaper_delete = http://localhost/all`** has no effect. No wallpaper delete key was found.
- **Two `wallpaper_upload.url` lines** both download in one pass.
- **`phone_setting.backgrounds_with_dsskey_unfold = Config:<file>`** is accepted (the wallpaper when the key panel is expanded). `%NULL%` restores the default.
- **Accepted:**
  - `transfer.dsskey_deal_type`
  - `features.missed_call_popup.enable`
  - `phone_setting.missed_call_power_led_flash.enable`
  - `phone_setting.custom_softkey_enable`
  - `account.N.blf.subscribe_period`
  - `account.N.nat.rport`
  - `features.factory_pwd_enable` (default 0)
  - `voice.handfree_send` (−50…50; the web UI says a reboot is needed, and the phone rebooted itself after provisioning)
  - `account.N.codec.opus.enable/priority` (by codec name, not slot number)
- **Removing a key from the config does not revert it on the phone.** Send the default explicitly.
- **After a reboot,** boot-time provisioning raced the tunnel once: status `autoprovision` for about 6 min. A check-sync then fetched within 1 s and returned to idle. On the next reboot, provisioning ran 2 s after the tunnel came up.

## 2026-11-12 (evening): bootstrap, admin passwords, backup

- **Bootstrap, new flow (factory-reset T87W, LAN):**
  1. 17:11:39: bootstrap answer "credential issued", with no secrets.
  2. 17:12:39: exactly 60 s later the phone came back with its own credential and got the full config, then wallpapers, ringtones, VPN profile and firmware.
  3. The device is claimed.
  - A reset phone still needs the PBX certificate added to its trusted certificates when the PBX uses a self-signed one.
- **Per-device admin password:**
  - On: the phone's password became the derived `1795316756`, and the shared one was rejected. OpenProv's screenshot through the tunnel still worked, re-learning the certificate pin. The pin was unchanged by the factory reset.
  - Off again: the shared password works again about 20 s after the fetch, and the per-device one is rejected.
- **Backup and restore:**
  - `fwconsole backup --backupsingle=openprov --singlesaveto=<dir writable by asterisk>` produced 84 MB: all OpenProv tables (not the request log), `modulejson`, and the uploaded files.
  - A scratch template added afterwards was gone after `--restoresingle`.
  - **The first run (2026-11-12) was wrong to report every row as matching.** FreePBX's `RestoreBase::importTables()` rewrites the two-character sequences `\n`, `\"` and `\'` in every value. That turned the Default template's escaped newlines (between its ringtone rules) into raw newlines, so its settings JSON no longer decoded, and the template read as empty: no ringtone rules, wallpaper, firmware or `{first}` labels. The check missed it because `mysql -B` prints a raw newline and `\n` the same way. The phone then fetched a config without the template's settings.
  - Fix: `Restore::importTable()` inserts the rows byte for byte with prepared statements. Stored JSON that won't decode now fails the render (the phone gets a 500 and keeps its config) and the template editor, instead of quietly becoming defaults.
  - Re-run 2026-11-13 with the fix, comparing rows through PDO: all six tables were byte-identical after `--restoresingle`, and the ringtone rules survived.
- **Extension rename probe (FreePBX GUI):** a new Display Name changes `users.name`, `devices.description`, the SIP `callerid` ("Name <ext>"), AstDB `AMPUSER/<ext>/cidname` and the voicemail box name. User Management is untouched. OpenProv's `extension.update` changes exactly those (verified on 205, then restored).
  - Saving the GUI form also rewrites every other field on it. On 201 that cleared the legacy `recording=force` and wrote about 20 default AstDB keys. Both were restored from a snapshot.
  - Core's GraphQL `updateExtension` deletes and recreates the extension and its User Management user.

## 2026-11-19: remote phonebooks, caller photos, transfer to voicemail (T87W 185.87.0.42, LAN, VPN off)

- **Phonebook authentication** (a probe HTTPS server logging what the phone sent):
  - A bare URL: one request without credentials, a 401, and no retry. The phone does **not** use its provisioning credential for a phonebook.
  - `https://user:pass@host/…`: a request without credentials, a 401, then a retry with the URL's credential. This is why OpenProv's phonebook URLs carry the phone's own credential.
- **When the phone downloads books:** right after a config that changes `remote_phonebook.data.N.url`, then every `features.remote_phonebook.flash_time` seconds (default 21600). Any changed URL made it re-download all its books.
- **OpenProv end to end:** a Contact Manager edit → `sync.changed` (the 5-minute job) → NOTIFY → config fetched → `/pb/2.xml` and `/pb/1.xml` fetched, all within 5 s. The phone parsed OpenProv's `<YealinkIPPhoneDirectory>` file.
- **Caller names:** with `features.remote_phonebook.enable = 1`, an incoming call from 201 showed the phonebook's name instead of the caller ID name FreePBX sent. A number in two books shows the name from the lower-numbered book.
- **Keys checked by import/export:** `features.remote_phonebook.flash_time` (default 21600) and `features.remote_phonebook.enter_update_enable` (default 0) exist; `directory_setting.remote_phone_book.enable` does not (dropped). `remote_phonebook.data.N.url = %NULL%` clears a slot (read back with `GET /api/contacts/remotephonebook/list`). The cfg export never lists `remote_phonebook.data.*`.
- **Caller photo at call time:** `account.1.picture_info_enable = 1` plus `Call-Info: <http://pbx/…/212.png>;purpose=icon` on the INVITE to the phone (FreePBX: `Set(HASH(__SIPHEADERS,Call-Info)=…)`, applied by `func-apply-sipheaders`). The phone fetched the 200×200 PNG about 1 s after the INVITE and showed it, round, on the ringing screen. Not built into OpenProv yet. Contact Manager keeps user images in `contactmanager_entry_userman_images` and contact images in `contactmanager_entry_images`.
- **Caller photo URLs (2026-11-20, T87W on the lab VPN as 10.8.0.2, probe server on the PBX):** the phone fetched `https://10.8.0.1:…` (lab certificate trusted), `http://10.8.0.1:…`, and `https://u1:p1@10.8.0.1:…` (no credentials, a 401, then a retry with `u1:p1`), all from its tunnel address. A LAN URL (`https://192.168.1.31:…`) was fetched from its LAN address, since the tunnel isn't its default route. Ports the FreePBX firewall doesn't open to the VPN zone get SYNs only. A rule added by hand with `iptables -I` was gone within about a minute (the firewall rewrites its chains).
- **Caller photos built in (2026-11-20, same phone):** `caller_photos` on, then Apply Config. A call to 212 with caller ID 5551234567 (a shared Contact Manager contact with a photo) put `Call-Info: <https://10.8.0.1:8449/openprov/photo/5551234567-…jpg>;purpose=icon` on the INVITE. The phone fetched it (200, a 3.6 kB JPEG) and showed the photo with the contact's name. A call from 201 (no photo) got a 404, and the phone showed initials. The same URL from the LAN got a 404 ("caller photo: not a VPN address"), and over plain HTTP a 403. The dialplan's `${SHA1()}` token matched OpenProv's. Not tried on hardware: ring groups (`macro-dial`), and removal of the header on a trunk leg (the lab has no trunk).
- **Transfer to voicemail key:** key type `transfer` with value `*<ext>` (FreePBX's Dial Voicemail Directly, `*`). Mid-call it sends a blind REFER (`transfer.dsskey_deal_type = 2`) and FreePBX runs `VoiceMail(<ext>@default,u)`: the unavailable greeting.
- **Driving the phone for tests:** the web API's keypad endpoint answers `error_invalid_request` on .42. From the PBX (OpenProv's Action URI allow list), `GET https://<phone>/servlet?key=OK` answers a call and `?key=L13` presses line key 13. `?key=DIRECTORY` does nothing, and arrow keys don't move the touch UI. A `directory` line key pressed with `?key=L<n>` opens the Directory. Screenshots: `device.screenshot`.
- **Lab pitfalls:**
  - The phone rejects the lab's self-signed certificate silently until it is in Trusted Certificates (`POST /api/certs/file?type=trusted&action=upload`, multipart field `file`).
  - Deploying files whose `module.xml` version is ahead of FreePBX's `modules` table works until FreePBX re-reads module versions (here after `fwconsole chown`). Then the module counts as disabled ("Pending upgrade") and every phone request is a 500 ("Unable to locate the FreePBX BMO Class 'Openprov'"). `fwconsole ma install openprov` fixes it.
