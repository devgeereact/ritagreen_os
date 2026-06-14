# RitaGreen Design System — v1 (LOCKED)

> The rebrand is this one page. Everything else is execution.
> **Direction:** *Warm front door, precise interior.* Landing = confident handshake
> (Unbounded + warmer green). App = Linear/Stripe-grade precision. Green is **surgical, not wallpaper.**
> Energy target: **Clean + trustworthy** — "this company has its act together."

---

## 1. Final decisions (the discipline)

1. **Neutral spine.** App canvas is white/off-white + warm grays — ~90% of every screen.
2. **Green is an accent, not a background.** Primary action, active nav, focus, one data highlight. **Never** a default card background, never a default border.
3. **Mint `#E9FDDB` + light border-green `#ADEA74` are banished from the app.** Marketing landing only.
4. **Unbounded = landing hero only.** App is 100% Albert Sans.
5. **One radius family, one shadow family, one spacing scale.** No exceptions.

> Rule of thumb: if a screen has green in more than ~2 places, it's wrong. Rip it out.

---

## 2. Color tokens

### Greens (the only color in the app)
| Token | Hex | Job |
|---|---|---|
| `green-primary` | `#4D7C0F` | Primary buttons, active state, links, key metric |
| `green-strong` | `#1A4E00` | Hover / pressed, dark green text-on-light |
| `green-focus` | `rgba(77,124,15,0.35)` | Focus rings only |
| `green-wash` | `#F1F6E9` | RARE — one subtle selected-row / highlight tint. Not a page bg. |

### Warm neutral ramp (foundation — tinted toward green, not cold)
| Token | Hex | Job |
|---|---|---|
| `n-0` | `#FFFFFF` | Surface / cards |
| `n-50` | `#F8FAF6` | App canvas background |
| `n-100` | `#EEF1EA` | Subtle fills, hover background |
| `n-200` | `#E0E5DA` | **Default borders / dividers (hairline)** |
| `n-300` | `#C7CEC0` | Strong borders, disabled outline |
| `n-400` | `#9BA394` | Placeholder, disabled text, muted icons |
| `n-500` | `#6E7568` | Secondary text |
| `n-600` | `#4C5346` | Body text |
| `n-700` | `#2E342A` | Headings |
| `n-900` | `#001E00` | Ink / primary text (original Text swatch) |

### Status (muted, clinical — not candy)
`success #4D7C0F` (reuse green) · `warn #B7791F` · `danger #B42318` · `info #2E6F9E`

---

## 3. Typography

- **Display — Unbounded:** landing hero + big page-title moments only. Weights 600/700.
- **UI / Body — Albert Sans:** everything in the app.

| Step | Size / Line | Weight | Use |
|---|---|---|---|
| Display | 48–64 / 1.05 | 700 | Landing hero (Unbounded) |
| H1 | 30 / 1.2 | 600 | Page title |
| H2 | 22 / 1.3 | 600 | Section |
| H3 | 18 / 1.4 | 600 | Card title |
| Body | 15 / 1.55 | 400 | Default |
| Small | 13 / 1.5 | 400 | Meta, labels |
| Micro | 11 / 1.4 | 500, caps, +4% tracking | Eyebrows, table headers |

Tables / metrics use **tabular-nums.**

---

## 4. Spacing — 4px base

`4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96`
Everything snaps to this. Default card padding **24**. Section gap **48**. When unsure → more air.

## 5. Radius

`sm 6` chips/tags · `md 8` buttons/inputs · `lg 10` cards · `xl 14` modals · `full 999` pills/avatars.

## 6. Elevation (soft, low-opacity — cards float, don't stamp)

- `shadow-1` cards: `0 1px 2px rgba(0,30,0,.05), 0 1px 1px rgba(0,30,0,.04)`
- `shadow-2` dropdowns/popovers: `0 4px 12px rgba(0,30,0,.08)`
- `shadow-3` modals: `0 12px 32px rgba(0,30,0,.12)`

Default cards: **border (`n-200`) first; add shadow-1 only if it must lift off the canvas.** Borders > shadows in clean UI.

## 7. Layout / containers

- App max content width **1200px**, gutters **24** (mobile) → **32** (desktop).
- Sidebar **256px** fixed; content fluid inside max-width.
- Landing: full-bleed sections allowed, content rail on same 1200.
- Breakpoints: `sm 640 · md 768 · lg 1024 · xl 1280`. Fluid between — no jumpy reflows.

## 8. Motion

One curve `cubic-bezier(.2,.8,.2,1)`, **160ms**. Hover / press / focus only. No decorative animation in the app.

---

## 9. Signature (what makes us original, not just clean)

1. **Surgical olive green.** Nobody in field-ops/staffing owns `#4D7C0F`. We own it **because** we starve it everywhere else — one green bite per screen.
2. **Line-style empty / loading states** in the icon language — even an empty roster feels deliberately RitaGreen.

Two signatures only. Everything else is restraint.

---

## 10. Build order (foundation before paint)

1. Neutral ramp + spacing + type + radius/shadow tokens. **No green yet.**
2. Re-skin core components (button, input, card, table, nav, modal) on neutrals.
3. **Then** add green — surgically, per the rules above.
4. Landing last (Unbounded + warmer green moments).

---

*Source brand assets:* Headline **Unbounded**, Body **Albert Sans**. Original palette swatches:
Background `#E9FDDB`, Text `#001E00`, Primary `#4D7C0F`, Accent `#1A4E00`, Surface `#FFFFFF`, Border `#ADEA74`.
