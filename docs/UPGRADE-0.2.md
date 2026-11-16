# Upgrading to 0.2.x: from port 8443 to /openprov

## What changed and why

On 2026-11-15, 0.1.x's Apache vhost (`Listen 8443`) collided with Sangoma
Connect's Node server, which already held `127.0.0.1:8443`. Apache's graceful
restart failed (`AH00072 … Address already in use`), Apache crash-looped, and
the FreePBX GUI was down until someone fixed it by hand. `apachectl configtest`
passed: a port conflict only shows when Apache binds.

From 0.2.x, phones use **`/openprov/` on FreePBX's own web server**. OpenProv
never writes to `/etc/apache2`, adds `Listen` ports or reloads Apache for
itself, so it can't cause this failure again. For HTTPS it uses FreePBX's own
HTTPS port when there is one (System Admin Port Management's SSL ports, or
443). On boxes without one, it runs its own small HTTPS service under pm2 on
port 8449, which forwards to `/openprov`. That's a separate process: a
conflict on its port only stops that service, never Apache. See DESIGN.md D3.
(0.2.0 assumed 443 and failed every request with HTTP 500; use 0.2.1 or
later.)

| | 0.1.x | 0.2.0 |
|---|---|---|
| Provisioning URL | `https://pbx:8443` | `https://pbx/openprov` (FreePBX's HTTPS on 443), `https://pbx:<port>/openprov` (another System Admin SSL port), or `https://pbx:8449/openprov` (OpenProv's HTTPS service). `fwconsole openprov web --check` prints it |
| DHCP option 66 | `https://user:pass@pbx:8443` | `https://user:pass@` + the URL above minus `https://` |
| Phone files | `https://pbx:8443/<mac>.cfg` | `<URL>/<mac>.cfg` (likewise `/a/…`, `/<mac>-vpn.tar`) |
| Certificate | `tls_cert`/`tls_key` settings | Certificate Manager's default certificate (FreePBX's web server or the service) |
| Firewall | custom service "OpenProv provisioning" (TCP 8443) | FreePBX's HTTPS: the firewall's Web Management service, custom service removed. OpenProv's service: the custom service, now on 8449 |
| Settings | `https_port` 8443, `http_port` 0, `tls_*` | `https_mode` (auto), `https_port` (0 = detect), `service_port` (8449), `http_port` (80); `tls_*` retired and ignored if set |
| Plain HTTP | only if `http_port` was set | refused (403) unless `phone_scheme = http` |

## What the upgrade does

`fwconsole ma upgrade openprov` (or a module-admin upgrade, then a root step):

1. **Settings, once:** clears the stored 0.1 `https_port`, `http_port` and
   `tls_*` values and prints them.
2. **Publishes `/openprov`:** creates the symlink `<AMPWEBROOT>/openprov` →
   `modules/openprov/htdocs`. This needs no root and makes no Apache change.
3. **Removes the 0.1 vhost, as root only:**
   - deletes `sites-enabled/openprov.conf` (the a2dissite step) and
     `sites-available/openprov.conf`;
   - runs `apache2ctl configtest`. If the remaining config (someone else's)
     fails, it stops there and reports, without a reload;
   - reloads Apache if it's running, or starts it if it's down (this recovers
     a box still crash-looping from the incident);
   - waits, checks `systemctl is-active apache2`, and restarts once if it isn't;
   - removes the "OpenProv provisioning" firewall custom service.
4. **HTTPS:** detects FreePBX's own HTTPS. If it finds none, it starts
   OpenProv's HTTPS service (pm2 `openprov-tls`, port 8449) and adds its
   firewall service, then prints the result.
5. **Upgraded from the GUI** (runs as `asterisk`, which can't touch
   `/etc/apache2`): steps 1–2 happen and the vhost stays. A critical FreePBX
   notification and a red banner on the OpenProv page say to run, as root,
   `fwconsole openprov web --install`. A root `fwconsole reload` does the
   same cleanup. The GUI never runs root commands.

On the incident box the vhost is already disabled (`a2dissite`), so the
upgrade just deletes the leftover file in `sites-available` and reloads a
healthy Apache.

## After upgrading: check, then repoint phones

1. `fwconsole openprov web --check` should print `Phones reach OpenProv at
   https://<pbx>[:port]/openprov (…)`. The Settings tab's Re-check button does
   the same. If it reports a problem, the message says what to change (a
   taken `service_port`, no pm2 module, `/openprov` not published, …).
2. **Phones that already have a `:8443` URL can't reach the server any more,**
   so they can't fetch the config that would tell them the new one. They keep
   working on their cached config (calls, registration), but get no updates
   until they're repointed. (This was decided: keeping the vhost for a
   transition would leave the crash risk in place.) Choose one:
   - **Phone web UI** (Settings → Auto Provision): Server URL = the URL from
     step 1. Leave the username and password fields as they
     are: an already-claimed phone holds its own per-device credential (user =
     its MAC), and that still works. Then click Auto Provision Now.
   - **DHCP option 66 / Yealink RPS:** change to
     `https://<bootstrap user>:<pass>@<pbx>/openprov`. A phone takes this only
     if it reads option 66 / RPS, normally after a reset. Before resetting a
     claimed phone, press **Re-arm** on its OpenProv page so the bootstrap
     credential works for it again.
   - **VPN phones on tunnel-bound models (T87W):** use the tunnel address
     with the same port and path, e.g. `https://10.8.0.1:8449/openprov` (or
     your `vpn_provisioning_host`).
3. Watch the Activity tab: each phone's next fetch shows the new path.

## Firewall

- **On FreePBX's own HTTPS port:** the firewall's Web Management service must
  allow the zone phones come from. Internal (which includes `tun0`) and Trusted
  do by default.
- **With OpenProv's HTTPS service:** OpenProv keeps a custom service, "OpenProv
  provisioning" (TCP `service_port`), in the zones from `firewall_zones`.

Use the VPN for remote phones rather than opening either to External.

## Rollback

Reinstalling 0.1.2 brings back the 8443 vhost and with it the crash risk. Only
do it on a box where nothing else uses 8443 (`ss -tlnp | grep ':8443 '` is
empty), and run `apachectl configtest && systemctl reload apache2 &&
systemctl is-active apache2` afterwards.

## Lab results (192.168.1.31, FreePBX 17.0.33, 2026-11-15)

The box has System Admin with no SSL ports (admin 80, UCP 81, provisioning
84) and Sangoma Connect (8001/8003). It was upgraded from 0.1.2 with
`fwconsole ma install openprov` as root.

| Check | Result |
|---|---|
| Upgrade output | ✅ Cleared `https_port=8443`, `http_port=0`, `tls_*`; removed the vhost (8443); "Apache reloaded and running" (same parent PID); removed the old firewall service; no HTTPS on FreePBX, so `openprov-tls` started on 8449 and its firewall service was added |
| First run | ❌ fixed: as root, the service's config was root-owned (0600), so `openprov-tls` (running as asterisk) couldn't read it. It's now chowned to asterisk |
| `web --check` | ✅ `Phones reach OpenProv at https://192.168.1.31:8449/openprov (OpenProv's HTTPS service)` |
| Phone URLs (T87W, tunnel-bound) | ✅ `https://10.8.0.1:8449/openprov` for config, firmware, wallpaper and VPN profile |
| Through the service from the LAN | ✅ ping 200; config without credentials or with wrong ones 401; paths outside `/openprov` 404. The Activity log shows the phone's real IP |
| Bypass attempts | ✅ Plain HTTP to Apache gives 403 (HTTPS required); a forged proxy header from the LAN gives 403; the wrong secret from loopback gives 403 |
| GUI / UCP after upgrade | ✅ 200 / 200, Apache `active` |
| 8449 taken by another process | ✅ Service not started; "Port 8449 is already in use by another process … Set service_port to a free port." in `web --check` and the GUI; Apache untouched (same PID), GUI 200. After the port was freed, the check started it again |
| Incident repro: 0.1 vhost + `127.0.0.1:8443` held + reload | ✅ Reproduced: `AH00072 … could not bind to address 0.0.0.0:8443`, `no listening sockets available`, Apache `activating`, GUI down. `fwconsole openprov web --install` removed the vhost and started Apache; GUI and UCP 200 again; the other process kept 8443 |
| `fwconsole start` with the service deleted from pm2 | ✅ "Running Asterisk post from Openprov module", service back online, ping 200 |
| T57W repointed to the lab through its web API, provisioning end to end | ✅ It refused the lab's self-signed certificate at first (TLS alert; the T57W only accepts trusted certificates by default). After the certificate was added to its trusted list: bootstrap, claim, files, VPN profile; tunnel up (10.8.0.4), ext 202 registered |
| VPN client reissue pushed to a phone (0.2.2) | ✅ T57W: client 5's certificate reissued (730 days = `CERT_DAYS_VAL`) and the old one revoked; openprov sent check-sync; new profile downloaded and tunnel up on the new certificate one second later; registration kept |
| SIP port (0.2.2) | ✅ Read from FreePBX 17's PJSIP transports (`Sipsettings`); before 0.2.2 openprov read chan_sip's table and always sent 5060 |
| T87W repointed to the tunnel URL (step 2) | ✅ Provisioned through the tunnel; new versioned VPN profile; reconnected |
| Full VPN rebuild with Prepare (0.2.2) | ✅ T87W held off the VPN, switched to direct over the LAN, rebuild, VPN given back automatically; new profile fetched directly; tunnel and SIP over the tunnel back in about 15 s. T57W (not held, as the first version only held tunnel-bound phones) was banned by fail2ban and reconnected after the unban, so Prepare now holds every VPN phone |

## Test plan

Run on a FreePBX 17 / Debian 12 test box with Sangoma Connect installed. For
every case, the final check is: the FreePBX GUI loads (`curl -skI
http(s)://localhost/admin/config.php` gives 200 or 302), `systemctl is-active
apache2` reports `active`, and `ss -tlnp | grep apache2` shows only Apache's
usual ports (no 8443).

**Automated (any machine with PHP 8.1, pdo_sqlite, gd):** `php tests/run.php`.
It covers publishing the path, removing the vhost (not as non-root; reload vs.
restart; no reload after a failed configtest; one restart if Apache dies),
refusing plain HTTP, `/openprov` in every phone URL, and HTTPS detection
(System Admin SSL port, then 443, then OpenProv's service; never starting the
service into a taken port; firewall service added and removed with it). It
also includes a source scan that fails if any code writes Apache config, adds
`Listen`, or enables sites or modules. Run the Node service directly with a
self-signed certificate and an upstream to check that it forwards, overwrites
forged forwarding headers, refuses plain HTTP and TLS < 1.2, gives 502 when the
upstream is down, and reports EADDRINUSE without exiting.

### 1. Fresh install while Sangoma Connect holds 8443
1. Confirm `ss -tlnp | grep ':8443 '` shows `node …/sangomaconnect/…`.
2. `fwconsole ma install openprov` as root, then again as `asterisk` on a
   second box.
3. Expect: `OpenProv: Phones are served at /openprov/…` and no Apache
   messages. `ls -l /var/www/html/openprov` is a symlink to `…/htdocs`.
   `/etc/apache2/sites-*/openprov.conf` doesn't exist. Apache's PID is
   unchanged (`systemctl show -p MainPID apache2` before and after).
4. `fwconsole openprov web --check` gives `Phones reach OpenProv at …`: the
   FreePBX HTTPS port if the box has one, otherwise `:8449` with
   `openprov-tls` online in `fwconsole pm2 --list`.
5. A test phone with DHCP 66 `https://user:pass@pbx/openprov`: it bootstraps,
   is claimed, and registers. Activity shows `/<mac>.cfg` with 200.
6. `curl -s -o /dev/null -w '%{http_code}' http://pbx/openprov/<mac>.cfg`
   gives `403` (HTTPS required).
7. Sangoma Connect still answers on 127.0.0.1:8443. Run the GUI check.

### 2. Upgrade from 0.1.2
1. On a box running 0.1.2 with its vhost enabled and working (no 8443
   conflict), with at least one claimed phone: note `fwconsole openprov
   settings.get`.
2. **As root:** `fwconsole ma upgrade openprov` (or `ma install` from the new
   tree). Expect the settings-cleared line, "Removed the old provisioning vhost
   (port 8443)", "Apache reloaded and running" and "Firewall: removed the
   'OpenProv provisioning' service". Both `openprov.conf` files are gone,
   `ss -tln | grep ':8443 '` is empty (or Sangoma Connect only), the firewall
   Custom Services list has no OpenProv entry, and `settings.get` shows
   `https_port: 443`.
3. **From the GUI** (module admin) on a second 0.1.2 box: the vhost files are
   still there and a critical notification plus a red OpenProv banner appear.
   Run `fwconsole reload` as root: the vhost is gone and the notification
   clears.
4. Repoint the phone (web UI, URL only). It fetches from
   `/openprov/<mac>.cfg` with its existing per-device credential. Then a
   check-sync (Resync button) works.
5. The incident box (vhost already `a2dissite`d): the upgrade deletes
   `sites-available/openprov.conf` and Apache stays up. Run the GUI check.
6. Run the GUI check.

### 3. Forced port conflict (GUI must stay up, error shown)
**OpenProv's HTTPS service port** (boxes without FreePBX HTTPS):
`timeout 60 python3 -m http.server 8449 &`, then `fwconsole pm2 --delete
openprov-tls` and `fwconsole openprov web --check`. Expect a non-zero exit
code and "Port 8449 is already in use by another process … Set service_port to
a free port", the same message on the Settings tab and as a red banner, and
Apache's PID unchanged with the GUI still loading. Free the port and run
`--check` again: the service starts.

**A 0.1 vhost left behind** (Apache itself):
1. On an upgraded box, recreate the 0.1 vhost by hand:
   `printf 'Listen 8443\n<VirtualHost *:8443>\n</VirtualHost>\n' >
   /etc/apache2/sites-available/openprov.conf && a2ensite openprov`, with
   Sangoma Connect on 8443. Don't reload.
2. Open the OpenProv page in the GUI: a red banner names the leftover vhost
   and the root command, `web.status` → `legacy.present: true`, and nothing
   has touched Apache (same PID).
3. `systemctl reload apache2`. Apache dies (the incident). Confirm the GUI is
   down.
4. `fwconsole openprov web --install` as root. Expect "Removed the old
   provisioning vhost (port 8443)" and "Apache reloaded and running" (it was
   inactive, so it's started). The GUI is back, the banner is gone, and any
   OpenProv notification is cleared.
5. Variant: also break another site (`echo bogus >
   /etc/apache2/sites-enabled/zz-test.conf`) before step 4. Expect "Did not
   reload Apache: apache2ctl configtest fails…", with no reload or restart
   attempted and OpenProv's file removed. Remove `zz-test.conf` afterwards.
6. Variant: `chmod 000` the module's `htdocs` (or move FreePBX's HTTPS to
   another port). Then `fwconsole openprov web --check` and the Settings test
   button report the error clearly. The GUI is unaffected.

### 4. Apache still serves the FreePBX GUI afterwards
After each case above: the admin GUI logs in, UCP (`/ucp`) loads, Sangoma
Connect still answers, and `apachectl -S` lists no OpenProv vhost. Reboot the
box once: Apache and Sangoma Connect both come up, whichever starts first, and
`/openprov/_ping` returns `openprov ok`.
