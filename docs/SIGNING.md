# Module signing

FreePBX checks every module's `module.sig` and warns about modules that
aren't signed by a key it trusts ("unsigned modules", "tampered files").
OpenProv is signed with its own release key, not by Sangoma. A system
trusts that key after running one command as root:

```sh
fwconsole openprov trust-key            # import the key and trust it
fwconsole openprov trust-key --status   # is it trusted?
fwconsole openprov trust-key --remove   # stop trusting it
```

Release key fingerprint (check it against the GitHub README and release notes):

```
E40F A5E8 A108 D1B8 2640  8E0D B63E 9EEE E8CD A76F
Matthew Jensen (FreePBX module releases) <matthewljensen@gmail.com>
```

## What trust-key changes

FreePBX verifies signatures with `gpg` as the web user (asterisk), using that
user's keyring (`~asterisk/.gnupg`). `trust-key` does three things:

- **Imports the key.** The public key comes from `keys/release-signing-key.asc` in this module, not from a keyserver. It is accepted only if its fingerprint matches the one pinned in the code.
- **Marks it ultimately trusted** in that keyring. FreePBX does the same for its own key.
- **Re-checks module signatures** so the warning updates right away. `fwconsole ma refreshsignatures` doesn't update it.

Trust is system-wide. Any module signed with this key passes, not only
OpenProv; the Convergent connector module is signed with the same key. The key
is used only to sign module releases. `--remove` deletes it from the keyring,
and from then on its modules show as unsigned again.

Without `trust-key`, OpenProv works the same. FreePBX just shows the warning.

## How the check works

`module.sig` is a clearsigned INI file listing the sha256 of every file in
the module. FreePBX (`admin/libraries/BMO/GPG.class.php`) accepts it when:

- the signature verifies and gpg reports the signer as trusted;
- no listed file is missing or changed (files not listed are ignored).

Sangoma's master key isn't required for this; it is required only for a
module to pass on systems that never ran `trust-key`. Hidden files (`.git`,
`.gitignore`, ...) are never listed or checked.

The signing subkey has no expiry on purpose. FreePBX rejects a signature from
an expired key even when it was made before the key expired ("Signed by
Expired Key"). An expiry would turn every installed release into a warning on
that date. Tested on FreePBX 17 (2026-11-13).

## Releasing (maintainers)

The release key lives on the maintainer's machine: the main key offline, a
signing subkey on the dev box, with a passphrase. Signing asks for it every
time.

```sh
git config core.hooksPath githooks   # once per clone
bin/module-sign sign                 # sign the committed tree, commit module.sig
bin/module-sign check [REV]          # does module.sig match REV (default HEAD)?
bin/module-sign release              # sign, tag v<version>, build dist/openprov-<version>.tgz
```

- `sign` signs what is **committed**, never the working tree, so it matches
  what `git clone` and `git archive` give a user. It refuses while tracked files
  have uncommitted changes.
- The `pre-push` hook runs `check` on anything pushed to `main` or a `v*` tag
  and refuses an unsigned or stale `module.sig`. So `main` always installs
  clean. `check` only needs the public key, so CI can run it too.
- `release` refuses if `v<version>` exists: bump `<version>` in `module.xml`
  first. Then push the commit and the tag, and attach the tarball to the
  release.
- A dev deploy of uncommitted work (rsync) shows as "altered" on that PBX
  until a signed version is installed. That is expected.

`bin/module-sign` is shared with the other modules signed by this key; keep
the copies identical.

If the signing subkey is ever lost or stolen: revoke it with the offline main
key, add a new signing subkey, update `keys/release-signing-key.asc`, and ship
a release. Systems that run `trust-key` again pick up the revocation and the
new subkey. The pinned fingerprint (the main key's) stays the same.
