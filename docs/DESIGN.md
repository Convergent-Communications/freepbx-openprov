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

## D3. A path on FreePBX's own web server, not a vhost or port of its own (revised 2026-11-15)

- **Chosen:** phones reach OpenProv at `/openprov/` on the web server FreePBX
  already runs. `<webroot>/openprov` is a symlink to the module's `htdocs/`;
  its `.htaccess` (mod_rewrite only) sends every path to `htdocs/index.php`,
  which refuses requests that don't come through that path. Install creates
  the symlink as whichever user runs it. No root is needed, and nothing in
  `/etc/apache2` is written.
- **HTTPS is detected, not assumed** (`Web::resolve()`, on install, Apply
  Config, settings changes and `web --check`). With `https_mode = auto`:
  1. **FreePBX's own HTTPS:** System Admin Port Management's `ssl*` ports, then
     443. The first one where `/openprov/_ping` answers is written into phone
     URLs.
  2. **Otherwise OpenProv's HTTPS service:** `node/tls-service.js` under
     FreePBX's pm2 module as `openprov-tls`, the way UCP and Sangoma Connect run
     their Node services.
     - It listens on `service_port` (default 8449; not 8443, which Sangoma
       Connect uses).
     - It uses Certificate Manager's default certificate, reloads it when the
       files change (Let's Encrypt renewals), and restarts on a new default
       certificate (certman hook).
     - It forwards to `/openprov` on Apache's loopback HTTP port, with the
       phone's IP and a shared secret that `index.php` trusts only from
       loopback.
     - It is started by `fwconsole start` (post-Asterisk hook).
     - If the port is taken, it isn't started; the reason shows in the GUI and
       `web --check`.
     - Its port is registered with the Firewall module as a custom service.

     It is a separate process, so a port conflict there can never affect
     Apache, not even at boot.

  The lab box (System Admin with no SSL ports) uses the service. `https_mode
  = freepbx | service` forces one side, and `https_port` pins FreePBX's port.
- **Hard rule:** OpenProv never writes Apache configuration, adds `Listen`
  directives, or reloads/restarts Apache for its own sake. A test
  (`web: no code writes Apache config…`) enforces it.
- **Why (incident 2026-11-15):** 0.1.x installed its own vhost with
  `Listen 8443`. On a box where Sangoma Connect's Node server already held
  `127.0.0.1:8443`, the graceful restart failed with `AH00072 … Address already
  in use`, and Apache crash-looped under systemd with the whole FreePBX GUI
  down. `apachectl configtest` passed, because a port conflict only shows at
  bind time. Any `Listen` a module adds can do this: Apache refuses to start
  if one of its sockets can't bind. A path on the existing listeners can't.
- **Why the 0.1 reasons for a separate port don't hold:**
  - *Firewall exposure* (opening provisioning to the internet without the
    admin GUI): remote phones provision through the System Admin VPN (D10), so
    the web port never needs to face the internet. A separate port that is
    open to the world mostly exposes the per-device credential scheme (D4),
    which is the same over 443.
  - *Isolation:* `index.php` serves only ProvisionServer. `/admin`'s
    `.htaccess` is untouched, and OpenProv's directory has its own.
  - *Own certificate:* phones get FreePBX's certificate (Certificate Manager,
    as for the GUI and UCP), so Let's Encrypt renewals are picked up with no
    extra step.
  - *Phone requirements:* none. Yealink takes a path in
    `auto_provision.server.url` / DHCP option 66, auth is HTTP Basic handled in
    PHP, and there are no client certificates (D4).
- **HTTPS:** the web server also answers plain HTTP on port 80, so with
  `phone_scheme = https` (the default) ProvisionServer refuses any non-HTTPS
  request with 403 before it looks at credentials. TFTP is ruled out.
- **Certificates (hardware finding, 2026-11-12):** the T57W refuses a
  self-signed certificate by default ("Only Accept Trusted Certificates"); the
  T87W accepted it. Production needs a publicly trusted certificate on
  FreePBX's web server (Certificate Manager / Let's Encrypt), or the CA pushed
  to the phones beforehand.
- **Rejected:**
  - *Own vhost and port (0.1.x)*: see the incident.
  - *Moving provisioning itself into Node*: OpenProv is PHP that needs
    FreePBX in-process (D1, D2). The pm2 service only terminates TLS and
    forwards to the same PHP, so there is one implementation.
- **Upgrade:** see `UPGRADE-0.2.md`. Removing the 0.1 vhost is the only
  remaining Apache action. It runs as root from fwconsole only (module
  install/upgrade, `fwconsole reload`, `fwconsole openprov web --install`),
  only ever deletes OpenProv's own file, and checks that Apache is running
  afterwards (starting it if it was down).

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
- **Certificates are System Admin's.** OpenProv never creates or renews them,
  so their lifetime is whatever System Admin used. On the lab its easy-rsa
  `pki/vars` holds 730 days for clients, the CA and the CRL, matching Certificate
  Manager's `CERT_DAYS_VAL` (Advanced Settings).
- **Rebuilds reach phones.** The profile URL carries `?v=<hash of CA, client
  cert/key, server address>`. A rebuilt or reissued certificate changes the
  phone's config, so change detection sends check-sync and the phone downloads
  the new profile. Tested 2026-11-15 on a T57W: client 5 reissued, old
  certificate revoked; profile fetched and tunnel back on the new certificate
  within a second. If a phone should have VPN but its profile can't be built
  (System Admin's client list or files unreadable, e.g. mid-rebuild), its
  update is held, not pushed.
  - **Tunnel-bound phones (T87W) can't follow a full rebuild.** The new CA drops
    the tunnel they provision through (D8a). Reissue those clients one at a
    time while their tunnel is up, or repoint them first. OpenProv can't add a
    warning to System Admin's rebuild button (another module), so it warns on
    its own side:
    - *Before:* the Settings tab, each affected phone's page and
      `vpn.rebuild-check` list the tunnel-bound VPN phones and the direct URL
      to repoint them to.
    - *After:* the job keeps the VPN CA's fingerprint. When it changes (a full
      rebuild), a critical FreePBX notification names each tunnel-bound phone
      that hasn't fetched since, with the URL to repoint it to, and each VPN
      phone whose tunnel hasn't come back 10 minutes on. It clears once they
      have all provisioned again and reconnected.
  - **Prepare for VPN rebuild** (Settings tab, `vpn.rebuild-prepare`) avoids
    both problems. Every VPN phone gets a config with VPN held off (a runtime
    override; the phones' own settings are untouched) and provisions directly:
    the LAN address, or `external_host` for a phone whose VPN connection comes
    from a public address. The Settings tab shows when all of them are ready.
    The admin rebuilds in System Admin; the job sees the new CA and gives the
    VPN back. The phones fetch their new profiles directly and reconnect.
    Phones handed back get a 15-minute grace before they count as cut off.
  - **fail2ban (found 2026-11-15).** A VPN phone that isn't held off keeps
    retrying its old certificate against the new CA. System Admin's fail2ban
    `openvpn` jail bans its address after a few failures, for 24 hours by
    default, and the `recidive` jail notes it too. The phone then gets its new
    profile but can't connect until it's unbanned (root: `fail2ban-client set
    openvpn unbanip <ip>`). Behind a shared NAT that's a whole site. That's why
    prepare holds every VPN phone, not just the tunnel-bound ones
    (`tunnelBoundOnly` restricts it).
  - **Tested on the lab, 2026-11-15:** prepare; T87W switched to direct over
    the LAN in about 1.5 minutes; full rebuild through System Admin's
    `rebuildCert`; new CA and client certificates (730 days); VPN given back;
    T87W fetched its new profile directly and its tunnel and SIP over the
    tunnel came back within about 15 seconds. The T57W, run without prepare
    (as a non-tunnel-bound phone was then), was banned by fail2ban and
    reconnected 10 seconds after the unban.
- **Expiry tracking:** client certificate, CA and CRL `nextUpdate` (the server
  enforces `crl-verify`, so an expired CRL stops every client). Shown on the
  phone page, via `vpn.expiring`, and as a FreePBX notification within
  Certificate Manager's `CERT_DAYS_EXPIRATION_ALERT` (default 30 days).
- **Remote address:** `vpn.cnf` takes the remote from the System Admin client
  record. The stale second `remote` line that System Admin writes is replaced
  with the external address from OpenProv's settings.

## D10. Provisioning URL per network

A device has a *provisioning network*: `auto` (the default), `internal`,
`external` or `vpn`. It picks the host and port written into
`auto_provision.server.url`, and the SIP server address.

- **`auto`** is `external` when `external_host` is set, else `internal`. With
  `external_host` set to the PBX's public DNS name, phones are configured by
  name, so they can be moved to another PBX by changing DNS. LAN phones then
  need the name to resolve to an address they can reach (split DNS or NAT
  hairpinning), or their template set to `internal`. It also lets the PBX use a
  publicly trusted certificate for that name, which models like the T57W
  require.
  - *VPN phones on `auto`* register SIP through the tunnel (`vpn_provisioning_host`,
    or the tunnel address) while still provisioning from the DNS name. Moving
    DNS still moves them: the new PBX hands out its own VPN profile, whose
    tunnel address is the new PBX. SIP over the tunnel is the point of giving a
    phone a VPN. Explicit `internal`/`external` keeps SIP on that network. A
    phone whose VPN is held (rebuild) or whose profile can't be built uses
    the direct address.

- **`vpn`** uses `vpn_provisioning_host`, or the VPN server's tunnel address
  (for example 10.8.0.1). The phone keeps provisioning through the tunnel after
  first contact, and the provisioning port never has to face the internet.
  Tunnel-bound models are forced onto this network (D8a).
- **Firewall:** on FreePBX's own HTTPS port, phones are covered by the
  firewall's Web Management service (`tun0` is Internal, which allows it). With
  OpenProv's HTTPS service, its port is the custom service "OpenProv
  provisioning", in the zones from `firewall_zones`. 0.1.x's 8443 entry is
  removed on upgrade.

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
