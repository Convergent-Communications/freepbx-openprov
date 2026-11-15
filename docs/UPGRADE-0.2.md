# Upgrading to 0.2.0: from port 8443 to /openprov

## What changed and why

On 2026-11-15, 0.1.x's Apache vhost (`Listen 8443`) collided with Sangoma
Connect's Node server, which already held `127.0.0.1:8443`. Apache's graceful
restart failed (`AH00072 … Address already in use`), Apache crash-looped, and
the FreePBX GUI was down until someone fixed it by hand. `apachectl configtest`
passed: a port conflict only shows when Apache binds.

From 0.2.0, phones use **`/openprov/` on FreePBX's own web server**, on the ports
Apache already listens on. OpenProv has no vhost, `Listen` port or server
process of its own. It never writes to `/etc/apache2` and never reloads Apache
for itself, so it can't cause this failure again. See DESIGN.md D3.

| | 0.1.x | 0.2.0 |
|---|---|---|
| Provisioning URL | `https://pbx:8443` | `https://pbx/openprov` (add `:port` only if FreePBX's HTTPS isn't on 443) |
| DHCP option 66 | `https://user:pass@pbx:8443` | `https://user:pass@pbx/openprov` |
| Phone files | `https://pbx:8443/<mac>.cfg` | `https://pbx/openprov/<mac>.cfg` (likewise `/a/…`, `/<mac>-vpn.tar`) |
| Certificate | `tls_cert`/`tls_key` settings | FreePBX's web certificate (Certificate Manager) |
| Firewall | custom service "OpenProv provisioning" (TCP 8443) | the firewall's Web Management service; custom service removed |
| `https_port` / `http_port` | the vhost's ports (8443 / 0) | FreePBX's web ports written into phone URLs (443 / 80) |
| Settings `tls_cert`, `tls_key`, `tls_chain`, `firewall_zones` | used | retired, and ignored if set |
| Plain HTTP | only if `http_port` was set | refused (403) unless `phone_scheme = http` |

## What the upgrade does

`fwconsole ma upgrade openprov` (or a module-admin upgrade, then a root step):

1. **Settings, once:** clears the stored 0.1 `https_port`, `http_port`, `tls_*`
   and `firewall_zones` values and prints them. Phone URLs now use 443.
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
4. **Upgraded from the GUI** (runs as `asterisk`, which can't touch
   `/etc/apache2`): steps 1–2 happen and the vhost stays. A critical FreePBX
   notification and a red banner on the OpenProv page say to run, as root,
   `fwconsole openprov web --install`. A root `fwconsole reload` does the
   same cleanup. The GUI never runs root commands.

On the incident box the vhost is already disabled (`a2dissite`), so the
upgrade just deletes the leftover file in `sites-available` and reloads a
healthy Apache.

## After upgrading: check, then repoint phones

1. `fwconsole openprov web --check` should print
   `Apache serves OpenProv at /openprov/`. (Settings tab → "Test from the PBX"
   does the same.) If it says "No answer", FreePBX's HTTPS is on another port:
   `fwconsole openprov settings.set https_port=<port>`.
2. **Phones that already have a `:8443` URL can't reach the server any more,**
   so they can't fetch the config that would tell them the new one. They keep
   working on their cached config (calls, registration), but get no updates
   until they're repointed. (This was decided: keeping the vhost for a
   transition would leave the crash risk in place.) Choose one:
   - **Phone web UI** (Settings → Auto Provision): Server URL
     `https://<pbx>/openprov`. Leave the username and password fields as they
     are: an already-claimed phone holds its own per-device credential (user =
     its MAC), and that still works. Then click Auto Provision Now.
   - **DHCP option 66 / Yealink RPS:** change to
     `https://<bootstrap user>:<pass>@<pbx>/openprov`. A phone takes this only
     if it reads option 66 / RPS, normally after a reset. Before resetting a
     claimed phone, press **Re-arm** on its OpenProv page so the bootstrap
     credential works for it again.
   - **VPN phones on tunnel-bound models (T87W):** use
     `https://10.8.0.1/openprov` (or your `vpn_provisioning_host`).
3. Watch the Activity tab: each phone's next fetch shows the new path.

## Firewall

Phones now arrive on FreePBX's web port. The FreePBX firewall's Web Management
service must allow the zone they come from. Internal (which includes `tun0`) and
Trusted do by default. Opening it to External also exposes the admin GUI and
UCP; use the VPN for remote phones instead.

## Rollback

Reinstalling 0.1.2 brings back the 8443 vhost and with it the crash risk. Only
do it on a box where nothing else uses 8443 (`ss -tlnp | grep ':8443 '` is
empty), and run `apachectl configtest && systemctl reload apache2 &&
systemctl is-active apache2` afterwards.

## Test plan

Run on a FreePBX 17 / Debian 12 test box with Sangoma Connect installed. For
every case, the final check is: the FreePBX GUI loads (`curl -skI
https://localhost/admin/config.php` gives 200 or 302), `systemctl is-active
apache2` reports `active`, and `ss -tlnp | grep apache2` shows only Apache's
usual ports (no 8443).

**Automated (any machine with PHP 8.1, pdo_sqlite, gd):** `php tests/run.php`.
It covers publishing the path, removing the vhost (not as non-root; reload vs.
restart; no reload after a failed configtest; one restart if Apache dies),
refusing plain HTTP, `/openprov` in every phone URL, and a source scan that
fails if any code writes Apache config, adds `Listen`, or enables sites or
modules.

### 1. Fresh install while Sangoma Connect holds 8443
1. Confirm `ss -tlnp | grep ':8443 '` shows `node …/sangomaconnect/…`.
2. `fwconsole ma install openprov` as root, then again as `asterisk` on a
   second box.
3. Expect: `OpenProv: Phones are served at /openprov/…` and no Apache
   messages. `ls -l /var/www/html/openprov` is a symlink to `…/htdocs`.
   `/etc/apache2/sites-*/openprov.conf` doesn't exist. Apache's PID is
   unchanged (`systemctl show -p MainPID apache2` before and after).
4. `fwconsole openprov web --check` gives `Apache serves OpenProv`.
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
OpenProv no longer binds a port, so the conflict can only come from a 0.1
vhost left behind:
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
