# Adding a phone model

Add a JSON file to `models/<brand>/`. No PHP changes are needed for Yealink
models.

```json
{
  "id": "T87W",
  "brand": "yealink",
  "name": "Yealink T87W",
  "ua": ["SIP-T87W"],
  "commonFile": "y000000000197.cfg",
  "accounts": 16,
  "lineKeys": 84,
  "expansion": {"modules": 3, "keysPerModule": 60},
  "wallpaper": {"width": 800, "height": 480},
  "verified": true,
  "notes": "How the numbers were checked",
  "defaults": {}
}
```

| Field | Meaning |
|---|---|
| `ua` | Token(s) from the HTTP User-Agent (`Yealink SIP-T87W 185.87.0.37 c4:fc:...`). The longest match wins |
| `commonFile` | Informational. The server answers any `y0000000000NN.cfg` and identifies the model from the User-Agent |
| `accounts` | SIP accounts. Unused ones are disabled |
| `lineKeys` | Line-key slots. All of them are written; unused ones are cleared with `%NULL%` |
| `expansion` | Expansion-module support, or `null` |
| `wallpaper` | Screen size in pixels, used to crop and resize wallpapers. `null` for monochrome screens |
| `defaults` | Raw keys applied first for this model (lowest priority) |
| `verified` | `true` only after the numbers were checked on hardware (see TESTING.md) |

## Verifying the numbers on a real phone

1. Point the phone at OpenProv. Its first requests (Activity tab) show the common file it asks for.
2. Count line keys: import a config with `linekey.N.type = 13` for N = 30, 40, ..., 100 and export it again. The highest N that survives is the count (the T87W stops at 84).
3. Take a screenshot (`https://<phone>/screencapture`, Basic auth) to get the screen resolution.

## Adding a settings option

Settings are data too, in `models/<brand>/schema.json`:

```json
{"id": "backlight_time", "label": "Backlight timeout", "group": "Display", "type": "number",
 "default": "", "keys": {"phone_setting.backlight_time": "{value}"}}
```

- `perAccount: true` repeats the keys for each configured account, with `{account}` filled in.
- `{invert}` writes 0 for 1 and 1 for 0.
- A blank default means "leave the phone's own value alone".
- Settings that need logic (`computed: true`) are handled in `src/Brand/Yealink.php`.

## Adding a brand

Implement `src/Brand/Brand.php` and register it in `Openprov.class.php` (the
`ConfigBuilder` brands map). Then add `ProvisionServer::handle` routing for its
paths, a `models/<brand>/` folder and a `schema.json`.
