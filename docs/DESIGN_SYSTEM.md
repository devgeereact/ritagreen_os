# Design system: v3, company green

**This is the single source of truth for the RitaGreen visual language.**

It documents what the code implements. It replaces v2, navy and mint, which is **retired**:
no navy or mint value remains in `src/`, and none should come back. The decision behind v3 is
the owner's (D05, 9 October 2026), recorded in [`docs/DECISIONS-2026.md`](DECISIONS-2026.md).

---

## 1. Brand position

One brand, **RitaGreen Services**, with four services named **Healthcare, Cleaning, Security and
Training** (decision D01). The public labels "Care" and "Academy" are retired. The internal ids
`care` and `academy` are unchanged, because they are stored in the database and used in routes:
map them to words through `src/lib/services.ts` (`SERVICE_LABELS`, `serviceLabel()`), never by
hand.

Services are told apart by a small coloured dot beside the word and by content, never by
re-skinning the interface.

Professional here means calm, predictable and readable. No gradients, glass panels, blobs,
glowing borders, heavy shadows, emoji, sparkle icons, animated counters, carousels or scroll
effects. Fewer, better things on each screen. Voice: plain, concrete, British.

## 2. Colour

HSL custom properties in `src/index.css` (`:root` and `.dark`), mapped to Tailwind tokens in
`tailwind.config.ts`. **Use the semantic token, never a raw hex.** The only hexes in
components are the service accents, which come from `src/lib/services.ts`.

### Core

| Role | Token | Hex | Use |
|---|---|---|---|
| Brand | `--primary` | `#33512D` (green-700) | Primary buttons, links, active navigation, the logo |
| Canvas | `--background` | `#F7F6F1` (paper) | Website background and app canvas |
| Surface | `--card`, `--popover` | `#FFFFFF` | Cards, forms, tables, menus |
| Ink | `--foreground` | `#1C221B` | Main text |
| Secondary text | `--muted-foreground` | `#5A6157` | Hints, meta, table headers |
| Neutral well | `--muted` | `#EFEEE8` | Quiet fills |
| Quiet green | `--secondary` | `#E2EBDE` (green-100) | Secondary buttons, tags, calendar events |
| Hover and selected | `--accent` | `#F1F5EF` (green-50) | Hover rows, selected items, active navigation background |
| Highlight | `--highlight` | `#B7791F` (ochre) | **One per screen at most.** A dot, a rule, a single marker |
| Divider | `--border` | `#DEDFD6` | 1px lines |
| Field edge | `--input` | `#BFC2B6` | Input borders, darker than dividers so a field meets 3:1 |
| Focus | `--ring` | `#33512D` | Focus rings, always visible |

The green scale behind these: 50 `#F1F5EF`, 100 `#E2EBDE`, 200 `#C6D6BF`, 300 `#9FB995`,
400 `#6F9363`, 500 `#4E7244`, 600 `#3E6136`, **700 `#33512D`**, 800 `#2A4325`, 900 `#1F3219`.
300 and 400 are decorative only. Green-900 is the website footer and careers band.

`--accent` is shadcn's hover surface, not a brand colour. Something that has to stand out uses
`--primary`, or `--highlight` once.

### Status

Status always carries a **symbol and a word**, so the meaning never depends on colour. Because
the brand is green, "valid" is deliberately not the brand green.

| Status | Text token | Background token | Text on background |
|---|---|---|---|
| Valid (tick) | `text-success` | `bg-success-soft` | `#1F6F4A` on `#E3F1E8` |
| Due soon (clock) | `text-warning` | `bg-warning-soft` | `#8A5A00` on `#FBF0D9` |
| Expired, error (cross) | `text-destructive` | `bg-destructive-soft` | `#A1261B` on `#FBE4E1` |
| Awaiting review (dot) | `text-awaiting` | `bg-awaiting-soft` | `#3B4452` on `#ECEEF1` |

Each also has a `-foreground` for a filled chip or button. Compliance uses these four and
nothing else: expired is destructive, expiring is warning, valid is success, unreviewed is
awaiting. Never invent a fifth colour for a compliance state.

### Service accents

A dot beside the word, a chart series, a calendar event edge. **Never** a page background, a
button or a filled surface.

| Service | Internal id | Accent |
|---|---|---|
| Healthcare | `care` | `#33512D` |
| Cleaning | `cleaning` | `#2C6E7F` |
| Security | `security` | `#3B4452` |
| Training | `academy` | `#B7791F` |

Defined once in `src/lib/services.ts`. `src/lib/service-pages.ts` (the public site's service copy)
and `src/lib/format.ts` (`DIVISION_LABELS`, `DIVISION_ACCENTS` for the office pages) read
from it. Training shares ochre with `--highlight`, so on a screen that shows service colours,
"open" or "attention" is `--warning`, not ochre: the rota's open shifts are drawn that way.

### Charts

`--chart-1` to `--chart-5`: green, teal, ochre, slate, sage. Fixed order, so a series keeps its
colour across the application. A chart split by service uses the service accents instead.

### Shadows

Tinted from `--shadow-tint` (ink in light, black in dark), low opacity. Shadow is for menus
(`shadow-elevate`) and dialogs (`shadow-lift`). Cards rely on their 1px border; `shadow-soft` is
barely there.

### Dark mode

The office app only; the website forces light. Every token is redefined under `.dark`:
green-black surfaces (`#101410` canvas), warm off-white text, and green-300 `#9FB995` as the
primary so links and buttons read against the dark. Toggled by class via `next-themes`; the
default is light.

## 3. Typography

All self-hosted in `public/fonts/` with faces in `src/fonts.css`, because the CSP is
`default-src 'self'` and the public site makes no third-party request. Latin and Latin Extended
subsets, SIL Open Font License.

| Use | Family | Weights | Tailwind | Size |
|---|---|---|---|---|
| App and staff portal text, headings included | **Source Sans 3** | 400, 500, 600, 700 | `font-sans` | 14 to 16px, line height 1.5 |
| Website body | **Source Sans 3** | as above | `font-sans` | 17 to 18px |
| Website headlines | **Source Serif 4** | 500, 600 | `font-display` (also `font-serif`) | 32 to 56px |
| Data and code | **Source Code Pro** | 400, 500 | `font-mono` | |

Source Serif 4 is the static files from `@fontsource/source-serif-4` 5.3.0. Website `h1` and `h2`
get it automatically: `PublicLayout` carries the `website` class, and `src/index.css` sets
`.website :is(h1, h2)` to the serif at 600 with `font-synthesis: none`, so a heading that asks for
bold gets 600 rather than a faked bold. The office app and the staff portal never use the serif,
and the worker app's install excludes its files (`vite.config.ts`, `globIgnores`).

**Plus Jakarta Sans is retired** and its files are removed.

**Tabular figures for data.** Times, money and counts use tabular figures (`.tabular`; every
`table` gets them automatically) so digits align down a column. Money is stored in pence and
rendered in pounds; never render a raw pence integer.

## 4. Shape and motion

**One radius: 8px** (`--radius: 0.5rem`), with `md` and `sm` derived from it. Change the
variable, not individual components. 1px borders.

Interface motion is 150 to 200ms: `fade-in` and the accordion run at 200ms, the sidebar chevron
at 150ms. Motion shows a relationship between states; it is never decoration. Reduced-motion
settings are honoured globally in `src/index.css`. The website's `fade-up` entrances, the hero
`animate-float` / `animate-glow` utilities, the gradient and grid backgrounds and the animated
counters were removed in S6; do not bring them back.

Focus rings are always visible: `:focus-visible` draws a 2px `--ring` outline on every
interactive element.

## 5. Layout

Container centred, 1.5rem padding, capped at 1200px at `2xl`.

Shells:

- `PublicLayout`: the website. Navigation, footer, storage notice, light only. Its building
  blocks (S6) are in `src/components/public/section.tsx` (`PageHeader`, `Section`,
  `SectionTitle`, `Steps`, `CheckList`, `ServiceTag`, `CareersBand`, `CtaBand`),
  `site-button.ts` (44px website buttons) and `forms/` (labelled native fields with errors tied
  by `aria-describedby`, focus to the first error, input kept on failure, a success panel that
  takes focus). The wordmark is `src/components/brand/Wordmark.tsx`. Website photographs are
  placeholders listed in `src/lib/site-images.ts`, never captioned as RitaGreen staff or clients.
- `AppLayout`: the office app. Sidebar rail, sticky header with search, theme toggle,
  notifications and the account menu. (The AI assistant that sat here was retired in S9.)
- The staff portal at `/m`, phone first.

### Office navigation: eight destinations

The sidebar (`src/lib/nav.ts`, drawn by `src/components/nav-main.tsx`) shows the plan's eight
destinations, each one link, with its secondary pages listed underneath when you are in that
area:

| Destination | Page | Secondary pages |
|---|---|---|
| Today | `/app` | |
| Recruitment | `/app/ats` | |
| People | `/app/workers` | Training |
| Compliance | `/app/compliance` | |
| Rota | `/app/scheduling` | Timesheets, Requests (cover requests and enquiries together), Clients, Jobs, Field ops, Incidents |
| Finance (only while switched on) | `/app/invoicing` | Quotes, Contracts, Payroll |
| Website | `/app/blog` (Posts) | Vacancies, Forms, Testimonials, Company details |
| Reports | `/app/reports` | |
| Settings | `/app/settings` | Inbox (only while switched on), Comms, Files, Help |

A link appears only if the account may open it (`src/lib/access.ts`) and its area is switched
on. A destination whose own page is closed to the account leads to its first open secondary
page. Collapsed to the icon rail, the secondary lists hide and each icon still links, with a
tooltip. Notifications are not in the rail; they live in the header bell and the ⌘K palette
(`src/lib/app-routes.ts`). Content Studio, Analytics and Insights were retired in S9; their old
addresses redirect to Posts and Reports.

### Page pattern

Title, one primary action on the right, a filter row, then content. Lists are tables on desktop
and stacked cards on phones. Empty states say what to do next. Errors say what went wrong and
keep what was typed.

## 6. Components

shadcn/ui (`new-york`) over Radix primitives, vendored into `src/components/ui/`, so they are
ours to modify.

- Extend an existing `ui/` component before adding a new one.
- Never hard-code a colour in a component. Use the token.
- Radix handles keyboard navigation, focus trapping and ARIA. Do not replace a Radix primitive
  with a hand-rolled one.
- Icons: Lucide. One icon set.
- A status chip is a symbol, a word and the soft background: see the Features card in Settings
  (`src/components/app/features-card.tsx`) for the pattern.

## 7. Logo, icons and manifest

`src/components/brand/Logo.tsx` draws the leaf from tokens, so it follows the theme: the tile is
`--primary`, the leaf is the text colour on it, the vein is cut back in the tile colour.
`public/favicon.svg` is the same mark in fixed colours: a green-700 tile and a green-100 leaf.

The web app manifest is **generated** by `vite-plugin-pwa` from `vite.config.ts` (theme colour
`#33512D`, background `#F7F6F1`), and `index.html` declares the same `theme-color`.

Icons: `icon-192.png`, `icon-512.png`, a separate 512px **maskable** icon (full-bleed green with
the leaf inside the central safe zone, because Android crops it to the launcher's shape) and a
180px full-bleed `apple-touch-icon.png` (iOS ignores an SVG touch icon). There is no script in
the repository. They were rendered from `public/favicon.svg` and two full-bleed variants with
ImageMagick, for example:

```bash
convert -background none -density 1536 public/favicon.svg -resize 512x512 -depth 8 -strip PNG32:public/icon-512.png
```

Regenerate them whenever the mark changes. `public/og.png`, the social share image, was redrawn in S6: 1200 by 630, green-700, the
wordmark and "Healthcare staffing, cleaning, security and training in London" in the
self-hosted fonts, rendered with headless Chrome from a one-off HTML page.

## 8. Retired artefacts

| Artefact | What it was | Status |
|---|---|---|
| v2 navy and mint (`#0C447C`, `#5DCAA5`, with `#1B2A3C` for Security) | The previous palette in `src/index.css`, the favicon, the manifest and this file | **Retired** in S1. Do not reintroduce |
| Plus Jakarta Sans | v2 display face | **Removed**, files and references |
| `DESIGN-SYSTEM.md` (v1) | Green `#4D7C0F`, Unbounded and Albert Sans | Deleted earlier |
| `RitaGreenOS.html` | A standalone specification with a third palette | Deleted earlier |

A document the application has stopped obeying is worse than no document, because it is
trusted. See `docs/RULES.md` rule 19.
