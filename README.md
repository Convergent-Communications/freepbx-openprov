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
- **Phone screenshots:** the phone page's Screen tab shows the live screen. The PBX fetches it, so it works for VPN phones too. The phone accepts the request only from the PBX's own addresses.
- **Automatic updates.** Phones update via SIP check-sync, including over the VPN. Tunnel-bound models (T87W) provision through the tunnel (see `docs/YEALINK-VPN-ISSUE.md`). A web-API reboot is available as an option.
- **EPM import:** migrates EPM's extension mappings.

## Install

```sh
cd /var/www/html/admin/modules
git clone https://github.com/Convergent-Communications/freepbx-openprov openprov
fwconsole ma install openprov        # publishes /openprov/ (no Apache changes); web --check shows the phone URL
fwconsole openprov trust-key         # as root, once per system: no "unsigned module" warning
```

- **Signed releases:** `main` and every release tag are signed with the OpenProv release key, fingerprint `E40F A5E8 A108 D1B8 2640 8E0D B63E 9EEE E8CD A76F`. FreePBX warns about modules that aren't signed by Sangoma. `trust-key` tells it to trust this key instead. Skip it and OpenProv still works, with the warning. See [module signing](docs/SIGNING.md) for what it changes and how to undo it.
- **Where phones connect:** `/openprov/` on FreePBX's own HTTPS port when it has one (System Admin Port Management or 443, detected). Otherwise OpenProv runs its own small HTTPS service under pm2 on port 8449 (`service_port`) that forwards to it. OpenProv never changes Apache's configuration. The certificate is Certificate Manager's default either way, so Let's Encrypt renewals apply automatically. `fwconsole openprov web --check` shows the URL phones use.
- **FreePBX firewall:** on FreePBX's own HTTPS port, phones need the firewall's Web Management service in their zone. With OpenProv's service, its port is added as the custom service "OpenProv provisioning" in the `firewall_zones` setting (default internal and other; the VPN interface `tun0` is Internal). Use the VPN for remote phones rather than opening either to `external`.
- **Updates:** `fwconsole ma upgrade openprov` (and FreePBX's scheduled module updates) installs the latest GitHub release, found through `<updateurl>` in module.xml. Versions before 0.2.3 don't have it: install 0.2.3 once with `fwconsole ma downloadinstall https://github.com/Convergent-Communications/freepbx-openprov/releases/download/v0.2.3/openprov-0.2.3.tgz`.
- **Upgrading from 0.1.x?** Read [docs/UPGRADE-0.2.md](docs/UPGRADE-0.2.md): the 8443 vhost is removed and phones must be pointed at the new URL.

## Bring a phone online

1. **Add the phone.** In Connectivity → OpenProv → Phones, click Add phone and enter the MAC, model, template and extension. Or use `fwconsole openprov device.save --data '{...}'`.
2. **Point the phone at the bootstrap URL** and credential from the Settings tab (e.g. `https://pbx/openprov`, or `https://pbx:8449/openprov` with OpenProv's HTTPS service):
   - DHCP option 66: `https://user:pass@pbx/openprov` (same host and port as the bootstrap URL)
   - Set `external_host` to the PBX's public DNS name and phones are configured by name (provisioning network "auto", the default). To move them to another PBX, change the DNS record.
   - or Yealink RPS
   - or the phone's own Auto Provision page

   On first contact the phone receives its own credential, and from then on the bootstrap credential no longer works for it.
3. **Edit keys and settings** in the GUI. Saving updates the phone automatically.

## Docs

- [Design decisions](docs/DESIGN.md), for review
- [Upgrading from 0.1 (port 8443 → /openprov)](docs/UPGRADE-0.2.md)
- [API: CLI, GraphQL, AJAX](docs/API.md)
- [EPM feature parity](docs/EPM-PARITY.md)
- [Testing and hardware results](docs/TESTING.md)
- [Adding phone models and settings](docs/ADDING-MODELS.md)
- [Add-on features (e.g. captions softkey)](docs/FEATURES.md)
- [Yealink VPN provisioning issue](docs/YEALINK-VPN-ISSUE.md)
- [Module signing and releases](docs/SIGNING.md)

## Development

```sh
php tests/run.php                  # no Composer needed
rsync -a --exclude .git ./ root@pbx:/var/www/html/admin/modules/openprov/ && ssh root@pbx 'fwconsole chown'
git config core.hooksPath githooks # once per clone: refuses to push an unsigned main
bin/module-sign sign               # sign what's committed (asks for the release key passphrase)
bin/module-sign release            # sign, tag v<version>, build dist/openprov-<version>.tgz and dist/update.json
gh release create v<version> dist/openprov-<version>.tgz dist/update.json   # both assets: update.json is what upgrades read
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
