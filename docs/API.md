# OpenProv API

There are three front ends, and all of them call the same actions in
`src/Api.php`:

| Front end | How to call it |
|---|---|
| CLI | `fwconsole openprov <action> [key=value ...] [--data JSON] [--compact]` |
| GraphQL (FreePBX API module, OAuth `gql` scope) | `openprovAction` mutation and `openprovQuery` query; typed `openprovDevices`, `openprovDevice`, `openprovTemplates`, `openprovKeyTargets` |
| GUI | `ajax.php?module=openprov&command=api&action=<action>` with a JSON body (needs a logged-in session) |

`fwconsole openprov actions` lists every action. Values in `key=value` CLI
arguments can be JSON (`devices='["c4fc22a1b2c3"]'`).

## Identifiers

- **Devices** accept an id or a MAC in any format (`c4:fc:22:a1:b2:c3`, `C4FC22A1B2C3`).
- **Templates** accept an id or a name.

## Actions

| Action | Arguments | Notes |
|---|---|---|
| `info` | | Version, web server status, counts, bootstrap URL |
| `settings.get` | `reveal?` | Secrets are masked unless `reveal=true` |
| `settings.set` | `{setting: value}` | See the Settings tab for the list |
| `models` | `brand?` | Phone model catalog (`models/<brand>/*.json`) |
| `schema` | `brand?` | Template/device settings and their Yealink keys |
| `keytypes` | `brand?` | Semantic key types (`blf`, `speeddial`, `feature`, ...) |
| `targets` | | Extensions, feature codes, park slots, ring groups (`category: ring_group`, speed dial, no hint) and toggles a key can point at. Includes `category` and `hint` (whether the BLF light works) for pickers and **permission gating** |
| `extensions` | | PBX extensions with display names |
| `phonebooks` | | Contact Manager groups the `phonebook_1`..`phonebook_5` settings can name: `[{id, name, type, owner}]` (`type`: internal, external or private; `owner`: the username for private groups) |
| `vpn.clients` | | System Admin OpenVPN clients |
| `templates.list` / `template.get` / `template.save` / `template.copy` / `template.delete` | | `template.save`: `{id?, name, parent?, description?, settings?, raw?, keys?}` |
| `devices.list` / `device.get` / `device.save` / `device.delete` | | `device.save`: `{id?\|mac, model, template?, name?, enabled?, settings?, raw?, lines?, keys?}` |
| `device.rearm` | `device, rotate?` | Accept the bootstrap credential again (after a factory reset) |
| `ringtones.builtin` | | Cached built-in ringtones per model: `{T87W: ["Ring1.wav", …]}` |
| `ringtones.fetch` | `device` | Copy that phone's built-in ringtones into the PBX cache (through its web API, tunnel first), converted to 16-bit PCM WAV |
| `ringtone.audio` | `ref, model?` | `{mime, data}` for listening. `ref` is `asset:<id>` (uploaded) or `Resource:<name>.wav` (built-in, from the cache) |
| `keys.prune` | | Remove keys that only repeat what is inherited (on every template and phone). Also runs automatically after saves. Returns `{removed}` |
| `dashboard.policy` | | What the dashboard may change here: `changes_allowed`, `key_types`, `rename_extensions`, `edit_user_details`, `settings` (with labels and options), `features` |
| `extension.get` | `extension` | Name, the User Management user (`uid, username, fname, lname, displayname, email`), and phones with this extension |
| `extension.update` | `extension, name?, fname?, lname?, displayname?, email?` | `email` is refused for remote (GraphQL/dashboard) callers: it is the Sangoma app login and where UCP password resets go. Renames **in place**: the same five places FreePBX's extension page changes (users, devices, SIP callerid, AstDB cidname, voicemail name). It also updates the given User Management fields; other fields are kept. Phones update. Asterisk's caller ID needs Apply Config, which the caller batches. Core's GraphQL `updateExtension` deletes and recreates the extension and its User Management user, so don't use it for renames |
| `device.sync` | `device, since?` | Live status: `{sync: current\|pending\|stale\|never\|error, last_seen, last_ip, auto_resync, now, events}`, with the phone's requests since `since` (server time). The GUI polls it after Save / Update phone now |
| `device.screenshot` | `device` | The phone's current screen: `{mime, data (base64), host, ts}`. The PBX fetches `/screencapture` from the phone (through the tunnel for VPN phones). Needs the "Allow screenshots from the PBX" setting (on by default) to have reached the phone |
| `keys.set` | `scope, ref, keys, bank?, replace?` | `scope` = `template`\|`device`. With `replace=false`, the keys given are merged slot by slot |
| `keys.fill` | `scope, ref, extensions\|"all", start?, bank?, type?` | Fills consecutive BLF keys |
| `render` | `device, reveal?` | Shows the config the phone gets; secrets are masked unless `reveal` |
| `resync` | `devices? \| extension? \| all?, reboot?` | Makes phones update now (see "Updating phones") |
| `sync.changed` | `dryRun?` | Updates every phone whose config changed since it last fetched |
| `devices.showing` | `extension` | Phones that show this extension on a line or a key |
| `assets.list` / `asset.add` / `asset.delete` | | `asset.add`: `{kind, name, path\|base64, model?, version?}` |
| `events` | `limit?, mac?` | Provisioning request log |
| `discovered` | `days?` | Unknown phones that asked for a config, plus registered Yealinks not managed here |
| `adopt` | `mac, model, extension?, template?` | |
| `export.csv` / `import.csv` | `csv` | Columns: `mac,model,template,name,extension,extension2,enabled` |
| `config.export` | `secrets?=false, firmware?=false, path?` | Everything as one JSON document (see "Export and import"). `path` (CLI only) writes it there, owner-only. Not allowed from the dashboard |
| `config.import` | `data \| upload \| path, dryRun?=true, settings?=true, secrets?=false` | Merges an export into this PBX. `data` = the document (object or JSON text); `upload` = a GUI upload id; `path` CLI only. Returns what was (or, with `dryRun`, would be) created/updated, plus `warnings` and `errors`. Not allowed from the dashboard |
| `upload.discard` | `id` | Deletes a GUI upload that won't be used (a cancelled import preview) |
| `import.epm` | `dryRun?=true, template?, modelMap?` | Copies commercial EPM mappings across |
| `vpn.rebuild-prepare` / `vpn.rebuild-finish` | `tunnelBoundOnly?=false` | Hold VPN off for the VPN phones (all, or only tunnel-bound) during a full VPN rebuild; given back automatically when the new CA is seen, or now with `finish`. Not allowed from the dashboard |
| `vpn.rebuild-check` | | Tunnel-bound VPN phones a full VPN rebuild would cut off (`stranded`, with `repoint_url`), how many others are pushed automatically, and, after a detected rebuild, the phones still `waiting` |
| `vpn.expiring` | `days?=30` | Phones whose VPN client certificate, the VPN CA or the CRL expires within `days` (expired included), soonest first |
| `web.status` | | `/openprov/` published?, how phones reach it over HTTPS (`https`: mode, port, ok, message), OpenProv's HTTPS service, any 0.1 vhost left |
| `web.check` | | Re-detects HTTPS (FreePBX's own, else OpenProv's service), then fetches `/openprov/_ping` as a LAN phone would |
| `web --install` / `--uninstall` / `--check` (CLI only) | | Publish/unpublish `/openprov` and resolve HTTPS; as root `--install` also removes the 0.1 vhost. Never writes Apache config |

## Keys

```json
{"bank": "line", "position": 2, "type": "blf", "value": "201", "label": "", "account": 1}
```

- **`bank`:** `line` or `exp1`–`exp6` (expansion modules).
- **`type`:** a semantic type from `keytypes`. `none` blanks a template key on a device. `yealink:NN` passes a raw Yealink DSS code.
- **`value`:** an extension, number, feature code or URL. `{ext}` is replaced with the phone's first local extension, so `*21{ext}` is that user's follow-me toggle.
- **`label`:** leave it empty to follow the PBX. Extensions use the person's display name in the template's `label_format`. Feature codes use the FreePBX feature name (for example "Main hours"). Park slots show "Park 71".

## Updating phones

- **Normal phones:** an AMI `PJSIPNotify` with `Event: check-sync;reboot=false` (or `reboot=true`). The phone fetches within about a second.
- **Phones with OpenVPN on (`vpn_update_method`):** Yealink 185.x ignores check-sync while the tunnel is up. OpenProv logs into the phone's web API (tunnel address first, then its last LAN IP) and reboots it, but only if the line is idle (`reboot_only_when_idle`) and the time is inside `reboot_window`. Otherwise the update is deferred and retried every 5 minutes by the `openprov sync` job.
- **Automatic updates:** after any OpenProv change, and on every FreePBX Apply Config (`genConfig` hook), `sync.changed` runs. A user rename in FreePBX (or from the dashboard, followed by an apply) updates every phone that shows that user.

## GraphQL examples

```graphql
query {
  openprovDevices { id mac model name firmware lastSeen lines { account extension } keys { position type value label } }
  openprovKeyTargets { category label type value hint perExtension }
}

mutation {
  openprovAction(input: {
    action: "keys.set",
    args: "{\"scope\":\"device\",\"ref\":\"c4fc22a1b2c3\",\"replace\":false,\"keys\":[{\"position\":8,\"type\":\"feature\",\"value\":\"*271\"}]}"
  }) { status message result }
}
```

`result` is the action's JSON output, as a string.

## CLI examples

```sh
fwconsole openprov device.save --data '{"mac":"c4:fc:22:a1:b2:c3","model":"T87W","template":"Default","lines":[{"account":1,"extension":"212"}]}'
fwconsole openprov keys.fill scope=device ref=c4fc22a1b2c3 extensions='["200","201","202"]' start=2
fwconsole openprov render device=c4fc22a1b2c3          # masked config
fwconsole openprov resync extension=213                # every phone that shows 213
fwconsole openprov asset.add kind=firmware path=/tmp/Yealink-T7X-185.87.0.37.rom model=T87W
fwconsole openprov import.epm modelMap='{"SIP-T57W":"T87W"}'   # dry run
```

## Export and import

`config.export` writes one JSON document (`openprov_export: 1`) with:

- **templates** (by name, with their parent's name, settings, raw lines and keys)
- **phones** (MAC, model, template name, name, enabled, settings, raw lines, lines, keys)
- **custom add-on features**
- **uploaded files**, base64: wallpapers and ringtones always; firmware only with `firmware=true` (40-90 MB each; the GUI leaves it out, use the CLI)
- **module settings** that aren't about this machine; template ids appear as names.

Never in a file: `internal_host`, `external_host`, `phone_scheme`, `https_mode`, `https_port`, `http_port`, `service_port`, `firewall_zones`, `internet_ports`, `allowed_networks`, `vpn_external_remote`, `vpn_provisioning_host`; the request log, discovered phones and sync state.

Only with `secrets=true`: the bootstrap credential, `admin_password`, `user_password`, `device_secret` and each phone's provisioning credential. Treat such a file like a password vault: it is everything needed to fetch every phone's SIP password.

`config.import` merges:

- **Matching:** templates by name, phones by MAC, features by id, files by content (SHA-256). Anything this PBX has that the file doesn't mention is left alone.
- **File ids:** settings that point at uploaded files are rewritten to this PBX's copies. A firmware file left out of the export is matched by name to one already uploaded here; otherwise the setting is dropped with a warning (upload it, then import again).
- **VPN:** a phone's VPN client id that doesn't exist here becomes `auto`, with a warning.
- **Phonebooks:** a `phonebook_N` naming a Contact Manager group that doesn't exist here is left out, with a warning. Group ids are per PBX: check the books after importing onto a different PBX.
- **Credentials:** without `secrets`, this PBX keeps its own bootstrap credential and passwords, and phones new to it get a fresh credential, so they bootstrap here (point them at this PBX, or Re-arm them). With `secrets` (only for a file exported with them), the file's values replace this PBX's, so phones moved here keep working with the credential they already have.
- **Errors:** a row that fails (an extension that doesn't exist here, an unknown model) is reported in `errors`; the rest still import.
- **Dry run** (the default): makes every change inside a database transaction and rolls it back, so the report shows exactly what would happen, errors included. Module settings are compared, not written.
- **Phones** that change are updated once, at the end (as after any save).

```sh
fwconsole openprov config.export path=/root/openprov.json                        # no passwords, no firmware
fwconsole openprov config.export path=/root/openprov-full.json secrets=true firmware=true
fwconsole openprov config.import path=/root/openprov.json                        # dry run: what would change
fwconsole openprov config.import path=/root/openprov.json dryRun=false
```

The GUI has the same on the Settings tab: Export (optionally with passwords), and Import a file → preview → Import or Cancel.

## Remote (GraphQL) callers and the dashboard policy

Every change made through GraphQL is checked against the **Dashboard** tab (the `dashboard_*` module settings; `Service::checkRemoteChange`):

- **Allowed:**
  - `keys.set` on a phone, with the allowed key types only. Keys of other types stay as set on the PBX.
  - `device.save` with allowed settings and features only. It is merged into the phone's settings.
  - `extension.update`
  - `resync` and `sync.changed`
- **Refused:** everything else (templates, raw keys, VPN, passwords, firmware, settings), with a message naming the setting to change.
- **Never delegated:** `Service::NEVER_FROM_DASHBOARD`, and any setting that isn't on/off, a number, a choice or text (wallpapers, ringtones, distinctive rules, passwords, computed settings): `Service::dashboardSettable()`.

GraphQL callers never get secrets: `reveal` is ignored, every value whose key looks like a password or secret is masked in replies (`Api::scrub`, e.g. a template's `admin_password` or a raw `static.security.user_password`), and they can't name server files.
