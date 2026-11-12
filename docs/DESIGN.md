# OpenProv design

OpenProv is a FreePBX 17 module that provisions desk phones (Yealink first).
It exists because the commercial Endpoint Manager (EPM) has no T7x/T8x support
and is closed. This file records the design decisions for review. Each one says
what was chosen, why, and what was rejected.

## D1. A FreePBX module, not a separate service

- **Chosen:** a normal FreePBX module (`rawname` `openprov`), installed with
  `fwconsole ma install`.
- **Why:** it can read FreePBX state in-process (extensions, SIP secrets, user
  display names, System Admin VPN clients). It installs and upgrades the way
  admins expect, and it can be open-sourced on its own.
- **Rejected:** a Node service inside asterisk-server. It would tie provisioning
  to the Convergent dashboard and duplicate FreePBX's data access.

## D2. Configs are rendered when the phone asks, not written to disk

- **Chosen:** the phone's request goes to one PHP front controller
  (`web/prov.php`). It builds the config from the database at that moment.
- **Why:**
  - Nothing is left on disk for others to read.
  - Nothing goes stale. A display-name change shows up on the next fetch
    without a "rebuild" step, so the EPM pattern of "rows highlighted yellow
    need rebuild" disappears.
  - It doesn't clash with EPM, autoprov or CalNu files in `/tftpboot`.
- **Cost:** each fetch runs PHP and SQL. This is trivial at phone scale.

## D3. Its own Apache virtual host, HTTPS first, on its own port

- **Chosen:** `fwconsole openprov web --install` writes
  `/etc/apache2/sites-enabled/openprov.conf`. It defines an HTTPS listener
  (default 8443, using certman's default certificate unless configured) and an
  optional HTTP listener (default off; port 8088 when enabled). Every path goes
  to `web/prov.php`; nothing else on the box is reachable through it. Writing
  Apache config needs root, so module install calls it only when running as
  root; otherwise the GUI and CLI show the one command to run.
- **Why a separate port rather than FreePBX's 443/80:**
  - **Firewall exposure.** Remote phones must reach provisioning from the
    internet. FreePBX's 443 is the admin GUI. A dedicated port can be opened to
    the world (or to customer networks) without exposing the admin GUI or UCP.
  - **Isolation.** The vhost serves exactly one script, ignores every
    `.htaccess` under /admin, and can have its own TLS settings, logs and
    certificate. Breaking or locking down the admin site doesn't affect
    phones, and the reverse.
  - **No collision** with System Admin's provisioning ports (84 and others) or
    with EPM, which keep working for older phones.
  - **Any port works.** It's a setting, so 443 is fine on a box with a
    dedicated IP or no admin GUI on 443.
- **Why HTTPS:** TFTP is ruled out, and plain HTTP sends SIP passwords in the
  clear.
- **Certificates (hardware finding, 2026-11-12):**
  - The T57W (96.87.0.20) refuses a self-signed certificate by default ("Only
    Accept Trusted Certificates"). The T87W accepted it.
  - Production therefore needs a publicly trusted certificate on the
    provisioning vhost (certman / Let's Encrypt for the PBX hostname), or the
    CA pushed to phones beforehand (RPS / factory config).
- **HTTP mode:** for labs only. It is off by default.

## D4. Per-device credentials, with trust on first use

**What it protects.** A phone's config contains its SIP password (and, with
VPN, its OpenVPN private key). Whoever can fetch `<mac>.cfg` can register as
that extension (toll fraud, eavesdropping on calls) or join the VPN.

**Why a single shared credential is weak.** That's the EPM / System Admin model,
and Yealink RPS and DHCP option 66 can only hand out one URL and credential for
all phones:
- The credential is on every phone: in its web UI, its config export and its
  DHCP options. One stolen or second-hand phone, or one curious user, has it.
- MACs aren't secret. Yealink MACs start with a handful of OUIs (`80:5e:c0`,
  `c4:fc:22`, `00:15:65`, `24:9a:d8`, ...) and are printed on the box and
  label, so the rest can be enumerated.
- **Shared credential + MAC = every phone's SIP password.** Rotating it means
  touching every phone.

**What OpenProv does instead:**
- **Bootstrap credential:** shared, as RPS requires. It only works for a phone
  that an admin has added and that hasn't been claimed yet. For anything else
  it's refused: unknown MACs, and phones already set up.
- **On first fetch (bootstrap):** the answer contains *only* a freshly generated device credential (username = its MAC), the direct provisioning URL and a 1-minute re-check. It has no SIP, VPN or admin secrets. Every bootstrap answer generates a new credential, so one handed out earlier stops working.
- **Claiming:** the phone comes back within a minute with its own credential and gets the full config. That fetch claims the device, and the bootstrap credential stops working for that MAC.
- **VPN profile:** never served for the bootstrap credential, only the device's own.
- **Uniform answer:** to the bootstrap credential, a claimed device and an unknown MAC look the same (404), so it can't be used to find out which MACs exist.
- **Afterwards:** a leaked bootstrap credential gets an attacker nothing for
  phones already in service. A leaked per-device credential exposes only that
  one phone.
- **Re-arm:** after a factory reset, "Re-arm" (optionally rotating the
  password) re-opens the bootstrap window for that one phone.
- **Extra checks:** the User-Agent MAC must match the requested MAC, an
  optional CIDR allow-list, and every request (including refused ones) is
  logged.

**Residual risk, accepted:**
- **The window between adding a phone and its claim.** During it, someone who has the bootstrap credential *and* knows that exact MAC can get a device credential. That alone yields no secrets. If they then fetch the full config before the real phone does, they claim the device, and the real phone is locked out: its bootstrap gets 404. That's loud: the phone won't provision, and the Activity log shows the other IP. If the real phone bootstraps first, the attacker's credential is replaced and dead.
- **Mitigations for that window:** add phones just before installing them, use
  `allowed_networks`, or turn `auto_adopt` off (the default).
- **Rejected:**
  - a shared credential only (above)
  - client certificates per phone (Yealink supports them, but they must be
    loaded before first contact, which defeats zero-touch).

**Talking to phones (screenshots, ringtone fetch, web-API reboot).**
- **Identity check:** before sending the phone's admin password, OpenProv checks that the phone's TLS certificate is issued to that device. Yealink phones carry a per-device certificate with CN = MAC.
- **Pinning:** the certificate's public key is then pinned (`openprov_devices.cert_pin`), and every later connection is pinned to it, so a machine that takes over the phone's old IP gets nothing.
- **Trust on first use:** the pin is learned on the first connection, preferably through the VPN tunnel. Re-arm clears it.
- **Accepted:** the Yealink device CA isn't available to verify the chain, so a spoofer present at that very first connection and presenting a self-signed cert with the right CN would be trusted.

**API callers.**
- **CLI (root):** may name server files (`asset.add path`).
- **FreePBX GUI:** may not name server files.
- **GraphQL tokens:** never get secrets (`reveal` is ignored) and can't name server files.

## D5. Layered settings

The final key/value map for a device is built in this order (later wins):

1. **Model defaults** (`models/<brand>/<model>.json` → `defaults`).
2. **Global settings:** server address, time zone, NTP, phone admin password,
   resync interval.
3. **Template chain.** A template may have a parent, so a site template can
   inherit from a company template.
4. **Device overrides.**
5. **Computed values:** SIP accounts, keys, VPN, wallpaper, firmware, and
   provisioning credentials.

Templates hold two things:

- **Friendly settings**, described by a brand *settings schema*
  (`models/<brand>/schema.json`). The GUI builds its forms from the schema, so
  a new setting is a JSON edit, not PHP.
- **Raw keys.** These replace EPM's basefile edit. Raw keys apply at template
  or device level and are written verbatim. They beat everything except the
  computed values that must stay correct: account secrets and provisioning
  credentials.

**Keys never pin a copy of the template.** After any save that touches keys or templates, a phone's (or child template's) key identical to the inherited key at that position is removed, so it follows the template again. So is a "blank" with nothing inherited under it. The phone's config doesn't change, so nothing is re-sent. Settings keep explicit values (pinning), since there an explicit default can be intentional.

**In the GUI** every setting and key shows where its value comes from:

- **Grey:** inherited. The field shows the value from the template (for a phone) or from the parent template and defaults (for a template).
- **Blue,** with a "this phone" / "this template" badge and a × reset: set at this level.
- **Yellow** key rows: edited but not saved.

The inherited entry names its source:

- **"(from Base)":** a template sets it.
- **"(default)":** OpenProv's built-in value. It is sent to the phone.
- **"Phone decides (not managed)":** nothing sets it, so OpenProv sends no key and the phone keeps its own value. That may be the factory default or a change made on the phone. This applies only to plain settings; a blank computed setting has its own meaning, given in its label.

Yealink's factory values are not shown. They vary by model and firmware, and OpenProv can't know them reliably.

The colours follow edits live. The backend supplies `template_view` (devices) and `parent_view` (templates) for this. Colour plus badge, rather than colour alone, keeps it readable for colour-blind users.

## D6. Phone models are JSON files

Adding a phone is a new `models/yealink/<model>.json`, with no code change. It
declares:

- the User-Agent pattern
- the common-config file name (for example `y000000000197.cfg` for the T87W)
- account count and line-key count (84 on the T87W, probed on hardware)
- expansion module support
- screen size, for wallpaper resizing
- the firmware filename pattern
- defaults

Brands are PHP classes behind a `Brand` interface. Only `Yealink` exists today.

## D7. Buttons are mapped to people, with labels resolved at fetch time

- **Storage:** a key row stores a *semantic* type (`blf`, `speeddial`, `line`,
  `park`, `voicemail`, `url`, ...), a value, and an optional fixed label. The
  brand translates the semantic type to its own codes, for example Yealink BLF
  = 16.
- **Labels:** when the label is empty and the value is a local extension, the
  label is the extension's current display name, using a per-template label
  format (`{name}`, `{first}`, `{ext}`, `{ext} {name}`). Because configs are
  rendered on request (D2), renaming a user changes the label on the next
  fetch.
- **Key sets:** template keys are the default layout. Device keys override it
  slot by slot.
- **Bulk helper:** fill BLF keys from a list of extensions, or from every
  extension, starting at a given slot.
- **Clearing old keys:** OpenProv emits every line-key slot the model has.
  Unused slots get `type = 0` and `%NULL%` label/value, so a key deleted on the
  server is cleared on the phone. An empty value would leave it unchanged;
  `%NULL%` clears it (verified on the T87W).

## D8. Phones update themselves automatically after changes

- **What triggers it:** FreePBX calls every module's `genConfig()` during
  "Apply Config" (`fwconsole reload`). OpenProv uses that hook to render a
  fingerprint of each device's config, compare it with the fingerprint from the
  last notify or fetch, and send a re-sync to every device whose config changed.
  The same check runs after any OpenProv API or GUI change.
- **Why reload:** the Convergent dashboard changes names through FreePBX
  GraphQL and then applies config. So "rename user → phone buttons update"
  needs no manual step and no dashboard code beyond what it already does.
- **How the re-sync is sent:**
  - It is an AMI `PJSIPNotify` with the header passed directly
    (`Event: check-sync;reboot=false`). This avoids `sip_notify*.conf` entirely,
    and with it the problems below.
- **Pitfalls found in the lab:**
  - Unescaped semicolons in notify config files.
  - The custom file name: `pjsip_notify.conf` includes
    `sip_notify_custom.conf`, not `pjsip_notify_custom.conf`.
  - Duplicate `[reboot-yealink]` sections from other modules, which merge into
    a NOTIFY with two Event headers.
- **Known firmware issue:** on T87W firmware 185.87.0.42, check-sync and
  "provision now" hang when the phone's VPN is on, but work when it's off. See
  D10 for the VPN-specific provisioning URL.

## D8a. Tunnel-bound phones provision through the tunnel (revised 2026-11-12 after hardware testing)

- **The problem:** on T87W 185.87.0.37 and .42, while OpenVPN is up, the provisioning downloader binds to `tun0` ("durl use network interface tun0"). A provisioning server at the VPN endpoint's own address is never contacted, and the task sits in `autoprovision` retrying until reboot. Addresses reached through the tunnel work, including check-sync. Full report: `YEALINK-VPN-ISSUE.md`.
- **Chosen:** models with `quirks.vpnBlocksProvisioning` (T87W) that have a VPN client always use the `vpn` provisioning network:
  - `auto_provision.server.url` and the SIP server use `vpn_provisioning_host`, or the server's tunnel address (10.8.0.1) when that's blank.
  - Wallpaper, ringtone, firmware and the VPN profile (`openvpn.url`) use a direct address (external, else internal) **until the phone's requests arrive from the VPN subnet**. After that they use the tunnel too, because a tunnel-bound phone can't reach anything else. The decision uses the request's source IP, or the device's `last_ip` for sync checks, so the hash the phone fetched matches what `sync.changed` renders.
  - `vpn_update_method = auto` now means check-sync for every model. Tested on the T87W (2026-11-12): fetch within 1–2 s over the tunnel, status back to idle.
- **Bring-up, tested on a factory-reset T87W:**
  1. Bootstrap over the LAN.
  2. The phone gets the VPN profile over the LAN and connects. No reboot was needed.
  3. It re-provisions from 10.8.0.2 about 4 s later, and SIP registers through the tunnel.
- **Still available:**
  - `reboot`: reboots through the phone's web API, with an idle check and `reboot_window`.
  - `none`: changes apply at the next reboot.
- **Risk: a broken VPN strands the phone.** If the tunnel can't come up (a revoked or regenerated client cert, or a VPN server change), a tunnel-bound phone's provisioning URL is unreachable. It can't be fixed remotely. Recovery is DHCP option 66, RPS or a factory reset. Mitigations:
  - Regenerate VPN clients only after the phones have fetched the new profile.
  - Set `vpn_provisioning_host` to a DNS name. It must still resolve to an address reached through the tunnel (not the endpoint IP), but it can be repointed later.
- **Rejected:** pushing config with the phone's import API. Imports are silently ignored while the phone is stuck in `autoprovision`.

## D9. System Admin VPN

- **Source:** the client certificates and config come straight from System
  Admin: `/etc/openvpn/clients/sysadmin_clientN.*` and
  `sysadmin_options.vpnclients`. This reuses the server the customer already
  licensed.
- **Tar:** the Yealink tar (`vpn.cnf` + `keys/`) is built in memory per
  request and served only to that device.
- **Choosing the client:** an explicit choice on the device, otherwise the
  client linked to the device's primary user in User Management (`vpn_client`
  or `vpn_allowed`).
- **Remote address:** `vpn.cnf` takes the remote from the System Admin client
  record. The stale second `remote` line that System Admin writes is replaced
  with the external address from OpenProv's settings.

## D10. Provisioning URL per network

A device has a *provisioning network*: `internal`, `external` or `vpn`. It
picks the host and port written into `auto_provision.server.url`.

- **`vpn`** uses `vpn_provisioning_host`, or the VPN server's tunnel address
  (for example 10.8.0.1). The phone keeps provisioning through the tunnel after
  first contact, and the provisioning port never has to face the internet.
  Tunnel-bound models are forced onto this network (D8a).
- **Firewall:** install registers a FreePBX firewall custom service, "OpenProv
  provisioning" (the HTTPS and HTTP ports), in the zones from `firewall_zones`
  (default internal and other; `tun0` is Internal). Changing the ports or
  zones in Settings updates it.

## D10a. Server config wins over phone-side edits

- **Setting:** `static.auto_provision.custom.protect = 0` is emitted by default (`keep_local_changes = 0`).
- **Why:** without it, labels edited earlier on the phone or its web UI silently beat the provisioned ones (seen on the T87W). The admin can opt back in per template.

## D10c. Ringtones are referenced by name; defaults are always sent (2026-11-12)

Based on T87W tests (TESTING.md):

- **Every ringtone is referenced by name:**
  - built-ins as `Resource:RingN.wav`
  - uploads as `Config:<file>`

  This covers the default ringtone and distinctive-ring ringers. The phone numbers custom ringtones alphabetically after its built-ins, and the built-in count differs by model, so numbers break when a file is added or the model changes.
- **Uploaded ringtones:** every one in use (the default plus any distinctive-ring ringers) is a `ringtone.url` line in the same config, and all of them download in one pass.
- **Distinctive ring:**
  - It's a list of `text=ref` rules. When set, all 10 phone slots are written, and unused ones get `%NULL%`, so a removed rule really disappears from the phone.
  - `none` clears the phone's rules. Blank = not managed.
- **Settings with Yealink factory defaults** (transfer on DSS key, missed-call pop-up/LED, BLF refresh, rport, Opus) always send their value. Dropping a key from a config does not revert it on the phone, so this is the only way "back to default" works.
- **Send volumes default to blank** (not managed), because changing one reboots the phone.
- **Built-in ringtones for listening** are copied from a phone of each model on request (`ringtones.fetch`) and cached on the PBX. They're Yealink's files, so they are not shipped with OpenProv.
- **Expanded-panel wallpaper:** `backgrounds_with_dsskey_unfold` defaults to the same file as the wallpaper. A different file is a second `wallpaper_upload.url` in the same pass, so no second provisioning pass is needed on the T87W. The T57W is untested.

## D10b. Firmware is delivered the same way as everything else

- **Mechanism:** firmware files are uploaded once (they're stored outside the web root) and assigned per template or device. `static.firmware.url` points at an authenticated URL, and the front controller streams the file.
- **Behaviour on the phone:** it compares the version header and stops reading when it already runs that version (about 1.8 MB read), so there's no upgrade loop.
- **Tested:** the downgrade from .42 to .37 went through this path.

## D11. API: one service layer, three front ends

- **Service layer:** `src/Service.php` holds every operation. The GUI (AJAX),
  the CLI and GraphQL are thin wrappers, so they can't drift apart.
- **CLI:** `fwconsole openprov <action> [--data JSON] [--json]`. Every action
  takes and returns JSON, which is easy for asterisk-server to call on the box.
- **GraphQL:** `Api/Gql/Openprov.php` adds typed queries and mutations to
  FreePBX's API module, which asterisk-server already uses (`lib/GraphQL.ts`).

## D12. No third-party runtime dependencies

- **Chosen:** plain PHP 8.2, using only what FreePBX already ships (PDO, GD,
  Symfony Console, the API module's GraphQL library).
- **Tests:** `tests/run.php` is a small assertion runner. It runs on the PBX
  with `php tests/run.php`, so no Composer is needed on the box.

## D13. Compatibility with EPM

- **Coexistence:** OpenProv never touches `/tftpboot`, EPM tables or notify
  config files. It can run next to EPM, which keeps serving older phones.
- **Migration:** `fwconsole openprov import-epm` copies EPM's extension
  mappings, images and VPN choices into OpenProv. Anything that can't be mapped
  is reported.

## Feature parity

See `docs/EPM-PARITY.md` for the EPM feature list and the status of each item.
