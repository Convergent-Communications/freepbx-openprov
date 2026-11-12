# Add-on features

An add-on feature is a JSON file in `models/<brand>/features/`. It describes config lines with variables, the phone resources it needs, and how to remove it. OpenProv turns each feature into ordinary settings (Settings → **Add-on features**), so templates, phone overrides and inheritance work as they do for everything else.

The first feature is `captions.json`: a call-screen softkey that opens live captions in the phone's XML browser.

## Settings each feature gets

| Setting | Values |
|---|---|
| `feature.<id>` | **On** writes the feature's `lines`. **Off** writes its `remove` lines. **Blank** means not managed: OpenProv writes nothing. |
| `feature.<id>.<variable>` | One setting per variable, with the default from the file |

**Off** and blank differ because a phone keeps settings that are simply left out of a later config. Switching a feature off needs the explicit removal values.

## File format

```json
{
 "id": "captions",
 "label": "Live captions softkey",
 "description": "…",
 "requires": ["edk", "xml_browser"],
 "claims": {"lineKey": 1, "softkey": 1},
 "variables": [{"id": "url", "label": "…", "default": "http://{pbx}:18990/captions/{ext}"}],
 "globals": {"features.enhanced_dss_keys.enable": "1"},
 "lines":  {"linekey.{lineKey}.type": "27", "softkey.{softkey}.action": "$KLineKey{lineKey}$"},
 "remove": {"linekey.{lineKey}.type": "0", "softkey.{softkey}.enable": "0"}
}
```

**Placeholders** in `lines`, `remove` and variable values:

- `{ext}`: the phone's primary extension.
- `{pbx}`: the PBX address for the phone's provisioning network. For VPN phones this is the tunnel address.
- `{mac}`: the phone's MAC address.
- `{<variable id>}`: the value of that variable's setting.
- `{lineKey}`, `{softkey}`: the slots this feature was given. A second claim of the same kind is `{lineKey2}`, and so on.

**`requires`** must be a subset of the model's `capabilities` (in the model JSON). If it isn't, the feature is skipped and the phone page shows a warning.

## Slots

Slots are handed out in a fixed order (features sorted by id) over the *managed* features, those set to On or Off. That keeps slot numbers stable, and an Off feature's removal lines hit the same slots it used before.

- **Softkeys:** 1, 2, … in that order.
- **Hidden line keys:** counting down from the model's last key (84 on the T87W), skipping keys the phone actually uses. If you put a real key on 84, the feature moves to 83, and the cfg changes accordingly.

**Globals** (e.g. the Enhanced DSS switch) are written while any feature that needs them is On. They are never turned off, because other things on the phone may rely on them.

**Order in the cfg:** feature lines are written after the line keys, so a hidden key wins over "clear line keys not defined here". Raw keys still override everything.

## Verified (T87W, 185.87.0.37, 2026-11-12)

- With captions On, the call screen shows the **Captions** softkey. A remote `servlet?key=L84` press from the PBX is accepted (HTTP 200) but has no effect, so the press has to be tested by hand.
- `softkey.X.position = 0` puts the key at the right end of the call softkeys on this firmware, not the left.
- Capabilities are verified only on the T87W. The T5x models carry `edk`/`xml_browser` as an unverified assumption.
