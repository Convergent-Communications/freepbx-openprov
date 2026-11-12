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

## D3. Its own Apache virtual host, HTTPS first

- **Chosen:** `fwconsole openprov web --install` writes
  `/etc/apache2/sites-enabled/openprov.conf`. It defines an HTTPS listener
  (default 8443, using certman's default certificate unless configured) and an
  optional HTTP listener (default off; port 8088 when enabled). Every path is
  rewritten to `web/prov.php`. Writing Apache config needs root, so module
  install calls it only when running as root; otherwise the GUI and CLI explain
  the one command to run.
- **Why:**
  - TFTP is ruled out by the owner, and plain HTTP leaks SIP secrets.
  - System Admin's provisioning vhost (`sangoma.conf`) isn't enabled on the
    Debian FreePBX 17 install, and it's ionCube-encoded, so we can't rely on it.
- **HTTP mode:** exists only for labs, and for phones that can't trust the
  certificate yet. It is off by default.

## D4. Per-device credentials, with trust on first use

- **The problem:** a factory-fresh phone only knows what DHCP option 66 or
  Yealink RPS tells it. That is necessarily a shared URL and credential.
- **First contact:** a phone may authenticate with the site-wide *bootstrap*
  credential only while its device row is `claimable`. That is the state when a
  device is first added, or re-armed after a factory reset.
- **Rotation:** the first config served sets per-device credentials
  (`static.auto_provision.server.username/password`) and turns
  `claimable` off. After that, only the per-device credential works for that
  MAC.
- **Extra checks:**
  - The requested MAC must match the MAC in the User-Agent.
  - An optional list of allowed source networks.
- **Logging:** every request is logged in `openprov_events`.
- **Rejected:** a single shared credential, which is what EPM and System Admin
  use. Anyone holding it can download every SIP secret by guessing MACs.

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

- **`vpn`** uses the VPN server's tunnel address (for example 10.8.0.1). The
  phone then keeps provisioning through the tunnel after the first contact, and
  the provisioning port never has to face the internet. Whether this also
  avoids the check-sync hang with VPN on is recorded in `docs/TESTING.md`.

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
