# Feature parity with the commercial Endpoint Manager

This list comes from clicking through EPM 17.0.16.6 on the lab box on
2026-11-12. "✅" means OpenProv does it and it was tested on the T87W unless
noted.

| EPM feature | OpenProv | Notes |
|---|---|---|
| **Global settings:** internal/external address, phone admin/user password, resync time | ✅ | Settings tab. Passwords are generated on install; `admin` is refused because it triggers the forced change-password screen |
| **Provisioning:** HTTP/HTTPS through System Admin with a shared credential | ✅ improved | Its own HTTPS vhost, per-device credentials, trust on first use, User-Agent MAC check, CIDR allow-list (DESIGN D3/D4) |
| **Brands** | Yealink | Brand interface for adding others (`src/Brand`) |
| **Phone models** | ✅ improved | JSON files; T7x/T8x supported (EPM has none). T87W verified, others from vendor data |
| **Templates:** default internal/external, SIP destination, provisioning address | ✅ | `prov_network` (internal/external/vpn), `sip_server`/`sip_port` overrides |
| **Templates:** time zone, NTP, DST | ✅ | Defaults to the PBX zone, mapped to Yealink zone names |
| **Templates:** background image | ✅ | Resized per model |
| **Templates:** line label format | ✅ | `account_label_format`, `label_format` |
| **Templates:** multicast paging | ✅ | Up to 10 listen addresses |
| **Templates:** dial patterns | ✅ | `dialnow` rules; other dial-plan keys go in raw keys |
| **Templates:** backup destination, outbound proxy (+ backup) | ✅ | `backup_server/port`, `outbound_proxy/port` |
| **Templates:** config style (legacy/v70) | n/a | Only current-style keys (v80+ firmware) |
| **Templates:** force firmware version | ✅ | Firmware asset per template or device |
| **Templates:** per-model line keys / soft keys | ✅ | One key layout per template, clipped to each model's key count; per-device overrides; 84 keys on the T87W. Programmable soft keys: raw keys for now |
| **Templates:** REST Phone Apps on keys | partial | Any XML browser URL works; no REST-apps picker yet |
| **Template inheritance** | ✅ new | EPM templates are flat |
| **Extension mapping:** ext/account → MAC/model/template | ✅ | Phones tab; several accounts per phone |
| **Extension mapping:** custom extensions (other server) | ✅ | "Other server (custom account)" line type |
| **Extension mapping:** expansion modules | ✅ | Per-module key banks (exp1–exp6) |
| **Extension mapping:** BLF substitution | ✅ replaced | Labels and values follow the PBX; `{ext}` placeholders |
| **Extension mapping:** VPN client | ✅ | `vpn` = off/auto/client id. "auto" reads the user's System Admin link |
| **Extension mapping:** rows highlighted as needing rebuild | ✅ replaced | Configs are rendered when requested, so there's no rebuild; "in sync" is reported per phone |
| **Extension mapping:** import/export CSV | ✅ | |
| **Extension mapping:** rebuild & update phones / reboot | ✅ | AMI NOTIFY; VPN phones get a web-API reboot (Yealink firmware issue, see YEALINK-VPN-ISSUE.md) |
| **Basefile edit** | ✅ improved | Settings schema for the common keys, plus raw `key = value` per template or device, without editing EPM's 2,700-row basefile one row at a time |
| **Image management** | ✅ | Files tab |
| **Ringtone management** | ✅ | EPM only does this for Sangoma phones |
| **Firmware management** (custom upload) | ✅ | Downgrade from .42 to .37 tested |
| **Network scan** (nmap) | ✅ replaced | Discover tab: unknown phones that asked for a config, plus Yealinks registered to the PBX. MAC from ARP when on the same LAN. No nmap dependency |
| **Base station management** (DECT W60B) | ❌ | Not started. Add W60B model JSON plus handset mapping |
| **DPMA / Sangoma S & D series** | ❌ | Out of scope (Sangoma-specific) |
| **Hot-desk emergency CIDs** | ❌ | Not started (hot desking isn't used) |
| **UCP: users customise their own keys** | ❌ | Planned through the Convergent dashboard instead (permission-gated) |
| **Automatic updates when a user is renamed** | ✅ new | Apply Config hook plus change detection |
| **API** | ✅ new | fwconsole JSON actions and GraphQL (EPM has only a minimal GQL) |
| **Feature-code BLFs** (time conditions, call flows, follow-me/DND/CF toggles, park slots) | ✅ new | Catalog with hint detection |
| **Coexistence with EPM** | ✅ | Doesn't touch `/tftpboot`, EPM tables or notify config files |

## Known gaps (not started, by decision or priority)

| Gap | Notes |
|---|---|
| DECT base stations (Yealink W60B/W70B) | Needs the base-station model plus handset ↔ extension mapping (a base serves several handsets) |
| Hot-desk emergency caller IDs | Not used today; EPM sets a fallback emergency CID when no one is logged into a hot-desk phone |
| REST phone apps picker | Keys can point at any XML browser URL; there's no picker for FreePBX's REST apps |
| Queue login/pause keys | Deferred (2026-11-12). FreePBX's queue hints depend on the agent and queue |
| UCP self-service | Planned through the Convergent dashboard (permission-gated), not UCP |
| Brands other than Yealink | Brand driver interface exists (`src/Brand`); no other driver yet |
