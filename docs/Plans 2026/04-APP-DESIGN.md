# 04 App design

One identity across website, office app and staff portal, with layouts suited to each task. Professional here means calm, predictable and readable. The visual board is [design-preview.html](design-preview.html).

## What to avoid, so it does not look generated

No gradients, glass panels, blobs, glowing borders, floating cards with heavy shadows, emoji, sparkle icons, animated counters, carousels, scroll effects, or slogans such as "Elevate your workforce". No stock photos of people who are not RitaGreen staff presented as staff. Fewer, better things on each screen.

## Colour

Built from the company green `#33512d`. Contrast against white is about 8.9:1 (passes WCAG AAA for text).

| Token | Hex | Use |
|---|---|---|
| green-50 | `#F1F5EF` | Selected rows, active navigation background |
| green-100 | `#E2EBDE` | Quiet section backgrounds |
| green-200 | `#C6D6BF` | Borders on green areas |
| green-300 | `#9FB995` | Decorative only |
| green-400 | `#6F9363` | Decorative only |
| green-500 | `#4E7244` | Hover on secondary elements |
| green-600 | `#3E6136` | Primary button hover |
| **green-700** | **`#33512D`** | **Brand: primary buttons, links, active navigation, logo** |
| green-800 | `#2A4325` | Pressed states, dark panels |
| green-900 | `#1F3219` | Website footer and careers band |
| paper | `#F7F6F1` | Website background and app canvas |
| surface | `#FFFFFF` | Cards, forms, tables |
| border | `#DEDFD6` | Dividers, inputs |
| muted | `#5A6157` | Secondary text |
| ink | `#1C221B` | Main text |
| ochre | `#B7791F` | One highlight per screen at most |

Service tags (a small dot and the word, never a filled surface): Healthcare `#33512D`, Cleaning `#2C6E7F`, Security `#3B4452`, Training `#B7791F`.

Status chips always carry a symbol and a word, so the meaning never depends on colour. Because the brand is green, "valid" is not shown in the brand green.

| Status | Text on background |
|---|---|
| Valid (tick) | `#1F6F4A` on `#E3F1E8` |
| Due soon (clock) | `#8A5A00` on `#FBF0D9` |
| Expired (cross) | `#A1261B` on `#FBE4E1` |
| Awaiting review (dot) | `#3B4452` on `#ECEEF1` |

Dark mode: kept for the office app (it exists today), derived from the same tokens. The website stays light.

## Type

| Use | Face | Size |
|---|---|---|
| App and portal text | Source Sans 3 (already self-hosted in `public/fonts/`) | 14 to 16px, line height 1.5 |
| Website body | Source Sans 3 | 17 to 18px |
| Website headlines | Source Serif 4 (new, self-hosted, two weights) | 32 to 56px |
| Times, money, counts | Source Sans 3 with tabular figures | as body |

Plus Jakarta Sans retires and its font files are removed.

## Shape and motion

Radius 8px. 1px borders. Shadow only on menus and dialogs. Transitions 150 to 200ms; respect reduced-motion settings. Focus rings always visible.

## Office app layout

```text
+------------------+-------------------------------------------------------+
| RitaGreen        |  Search people, shifts, sites          [bell] [ME]    |
|                  +-------------------------------------------------------+
| Today            |  Today, Thursday 9 October            [Add shift]     |
| Recruitment      |                                                       |
| People           |  [Unfilled shifts 4] [Checks to review 7] [Expiring 12]|
| Compliance       |                                                       |
| Rota             |  Today's shifts                          Open rota >  |
| Website          |  07:30  Oakwood Care Home   A. Mensah   Healthcare  OK |
| Reports          |  08:00  Riverside School    Open        Cleaning   !   |
| Settings         |                                                       |
|                  |  Needs action                                          |
|                  |  New applicant: support worker       Review >         |
|                  |  DBS certificate to verify           Verify >         |
+------------------+-------------------------------------------------------+
```

Page pattern everywhere: title, one primary action on the right, a filter row, then content. Lists are tables on desktop and stacked cards on phones. Empty states say what to do next. Errors say what went wrong and keep what was typed.

Person profile: header with photo, name, service tags with status, and the main next action (for example "Verify DBS"). Tabs from file 02 underneath.

Recruitment board: columns by stage, cards show name, role, service, days in stage and next task. Opening a card opens the person profile on the Recruitment tab.

## Staff portal layout

```text
+---------------------------+
|  Morning, Ama        [ME] |
|                           |
|  NEXT SHIFT               |
|  Tomorrow 07:30 to 19:30  |
|  Oakwood Care Home        |
|  12 Park Road, SE13  Map >|
|  Site notes: sign in at.. |
|  [ Clock in ] from 07:15  |
|                           |
|  Training due             |
|  Moving and handling, 14d |
+---------------------------+
| Home | Rota | Records | Me|
+---------------------------+
```

## Implementation notes for S1

Tokens change in one place: the HSL variables in `src/index.css` and their mapping in `tailwind.config.ts`. The 37 vendored shadcn components are reused. Update `theme-color` in `index.html`, the favicon, PWA icons and the manifest colour in `vite.config.ts`. Then rewrite `docs/DESIGN_SYSTEM.md` (it currently declares navy and mint as the standard).
