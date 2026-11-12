# OpenProv API

There are three front ends, and all of them call the same actions in
`src/Api.php`:

| Front end | How to call it |
|---|---|
| CLI | `fwconsole openprov <action> [key=value ...] [--data JSON] [--compact]` |
| GraphQL (FreePBX API module, OAuth `gql` scope) | `openprovAction` mutation and `openprovQuery` query; typed `openprovDevices`, `openprovDevice`, `openprovTemplates`, `openprovKeyTargets` |
| GUI | `ajax.php?module=openprov&command=api&action=<action>` with a JSON body (needs a logged-in session) |

`fwconsole openprov actions` lists every action. Values in `key=value` CLI
arguments can be JSON (`devices='["c4fc221dd637"]'`).

## Identifiers

- **Devices** accept an id or a MAC in any format (`c4:fc:22:1d:d6:37`, `C4FC221DD637`).
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
| `targets` | | Extensions, feature codes, park slots and toggles a key can point at. Includes `category` and `hint` (whether the BLF light works) for pickers and **permission gating** |
| `extensions` | | PBX extensions with display names |
| `vpn.clients` | | System Admin OpenVPN clients |
| `templates.list` / `template.get` / `template.save` / `template.copy` / `template.delete` | | `template.save`: `{id?, name, parent?, description?, settings?, raw?, keys?}` |
| `devices.list` / `device.get` / `device.save` / `device.delete` | | `device.save`: `{id?\|mac, model, template?, name?, enabled?, settings?, raw?, lines?, keys?}` |
| `device.rearm` | `device, rotate?` | Accept the bootstrap credential again (after a factory reset) |
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
| `import.epm` | `dryRun?=true, template?, modelMap?` | Copies commercial EPM mappings across |
| `web.status` / `web.config` | | Provisioning vhost |
| `web --install` / `web --uninstall` (CLI only, as root) | | Writes and enables the Apache vhost |

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
    args: "{\"scope\":\"device\",\"ref\":\"c4fc221dd637\",\"replace\":false,\"keys\":[{\"position\":8,\"type\":\"feature\",\"value\":\"*271\"}]}"
  }) { status message result }
}
```

`result` is the action's JSON output, as a string.

## CLI examples

```sh
fwconsole openprov device.save --data '{"mac":"c4:fc:22:1d:d6:37","model":"T87W","template":"Default","lines":[{"account":1,"extension":"212"}]}'
fwconsole openprov keys.fill scope=device ref=c4fc221dd637 extensions='["200","201","202"]' start=2
fwconsole openprov render device=c4fc221dd637          # masked config
fwconsole openprov resync extension=213                # every phone that shows 213
fwconsole openprov asset.add kind=firmware path=/tmp/Yealink-T7X-185.87.0.37.rom model=T87W
fwconsole openprov import.epm modelMap='{"SIP-T57W":"T87W"}'   # dry run
```
