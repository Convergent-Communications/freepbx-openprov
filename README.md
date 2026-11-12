# OpenProv: open-source phone provisioning for FreePBX

OpenProv is a FreePBX 17 module that provisions Yealink desk phones, including
the T7x/T8x models the commercial Endpoint Manager doesn't support. It is
built to be driven by people (GUI) and by software (CLI and GraphQL).

- **On-demand configs over HTTPS.** Nothing is written to `/tftpboot`, and it can run next to EPM.
- **Secure provisioning.** Each phone gets its own credential, adopted on first contact. Also: User-Agent MAC checks, a CIDR allow-list, and a log of every request.
- **Buttons follow people.** BLF and speed-dial labels come from users' display names. Renaming a user and applying config updates every phone that shows them.
- **Feature-code keys with live state:** time conditions, call flows, follow-me/DND/call-forward toggles, park slots. A catalog of everything the PBX can light up.
- **Layered templates:** parent template → template → phone. Data-driven settings, plus raw `key = value` lines for everything else.
- **System Admin VPN:** OpenVPN profiles built from System Admin's clients.
- **Files:** wallpapers (resized per model), ringtones, firmware.
- **Automatic updates.** Phones update via SIP check-sync, or a safe web-API reboot for VPN phones (see `docs/YEALINK-VPN-ISSUE.md`).
- **EPM import:** migrates EPM's extension mappings.

## Install

```sh
cd /var/www/html/admin/modules
git clone https://github.com/<you>/freepbx-openprov openprov
fwconsole ma install openprov        # as root: also installs the HTTPS vhost (port 8443)
```

- **Install as a non-root user?** Run `fwconsole openprov web --install` as root afterwards.
- **FreePBX firewall:** if it's enabled, add TCP 8443 as a custom service (Connectivity → Firewall → Services) and enable it for every zone phones provision from, including the zone of the VPN interface (`tun0`, Internal by default). Otherwise VPN phones can't reach OpenProv.

## Bring a phone online

1. **Add the phone.** In Connectivity → OpenProv Provisioning → Phones, click Add phone and enter the MAC, model, template and extension. Or use `fwconsole openprov device.save --data '{...}'`.
2. **Point the phone at the bootstrap URL** and credential from the Settings tab (e.g. `https://pbx:8443`):
   - DHCP option 66: `https://user:pass@pbx:8443`
   - or Yealink RPS
   - or the phone's own Auto Provision page

   On first contact the phone receives its own credential, and from then on the bootstrap credential no longer works for it.
3. **Edit keys and settings** in the GUI. Saving updates the phone automatically.

## Docs

- [Design decisions](docs/DESIGN.md), for review
- [API: CLI, GraphQL, AJAX](docs/API.md)
- [EPM feature parity](docs/EPM-PARITY.md)
- [Testing and hardware results](docs/TESTING.md)
- [Adding phone models and settings](docs/ADDING-MODELS.md)
- [Yealink VPN provisioning issue](docs/YEALINK-VPN-ISSUE.md)

## Development

```sh
php tests/run.php                  # no Composer needed
rsync -a --exclude .git ./ root@pbx:/var/www/html/admin/modules/openprov/ && ssh root@pbx 'fwconsole chown'
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
