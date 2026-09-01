# Design system — v2, navy and mint

**This is the single source of truth for the RitaGreen visual language.**

It documents what the code actually implements. Where a previous document disagreed, the code
won and the document was retired — see "Retired artefacts" at the end.

---

## 1. Brand position

RitaGreen is **one master brand**. Care, Academy, Cleaning and Security share the same name,
the same colours and the same standard. Only the descriptor word changes:

> RitaGreen **Care** · RitaGreen **Academy** · RitaGreen **Cleaning** · RitaGreen **Security**

Divisions are distinguished by an accent dot and by content, never by re-skinning the
interface. A visitor moving between divisions should never feel they have left the company.

Voice: plain, concrete, British. "Qualified. Checked. Ready." Not aspirational language.

## 2. Colour

Implemented as HSL CSS custom properties in `src/index.css`, consumed through Tailwind
semantic tokens in `tailwind.config.ts`. **Always use the semantic token, never a raw hex.**

### Core

| Role | Token | Light | Hex |
|---|---|---|---|
| Primary — navy | `--primary` | `210 82% 27%` | `#0C447C` |
| Accent — mint | `--accent` | `160 51% 58%` | `#5DCAA5` |
| Surface tint | `--secondary` | `209 72% 94%` | `#E6F1FB` |
| Background | `--background` | `0 0% 100%` | `#FFFFFF` |
| Ink | `--foreground` | `213 50% 12%` | — |

Navy carries authority and does the structural work. Mint is an accent — highlights, success
states, the one thing on a screen that should be looked at first. It is not a second primary.

### Status

| Role | Token | Use |
|---|---|---|
| Destructive | `--destructive` | Errors, expired credentials, blocked actions |
| Warning | `--warning` | Expiring credentials, attention needed |
| Success | `--success` | Valid, verified, complete |

Compliance status uses these three consistently across every surface. Expired is destructive,
expiring is warning, valid is success. Never invent a fourth colour for a compliance state.

### Charts

`--chart-1` through `--chart-5`: navy, mint, neutral, pale blue, amber. Fixed order, so the
same series keeps the same colour across the application.

### Dark mode

Every token above is redefined under `.dark` in `src/index.css`. Primary and accent brighten
for contrast against a deep navy canvas. Toggled by class via `next-themes`; the default is
light.

## 3. Typography

Loaded from Google Fonts in `index.html`. Three families, three jobs.

| Role | Family | Weights | Tailwind |
|---|---|---|---|
| UI and body | **Source Sans 3** | 400, 500, 600, 700 | `font-sans` |
| Display | **Plus Jakarta Sans** | 500–800 | `font-display` |
| Data and code | **Source Code Pro** | 400, 500 | `font-mono` |

`font-display` is for page-title hero moments and nothing else. Overuse flattens the
distinction and the hierarchy stops working.

**Tabular figures for data.** Every table of numbers, every metric, every money value uses
tabular figures so digits align down a column. Money is stored in pence and rendered in
pounds; never render a raw pence integer.

## 4. Shape and elevation

Radius is driven by one variable, `--radius: 0.625rem`, with `md` and `sm` derived from it.
Change the variable, not individual components.

Three elevations, navy-tinted and low opacity. Cards float; they do not stamp.

| Token | Use |
|---|---|
| `shadow-soft` | Cards |
| `shadow-elevate` | Popovers, dropdowns |
| `shadow-lift` | Modals, sheets |
| `shadow-ring` | Hairline definition |

## 5. Motion

Defined in `tailwind.config.ts`. Restrained and purposeful.

| Animation | Duration | Use |
|---|---|---|
| `fade-up` | 0.6s | Content entering on scroll |
| `fade-in` | 0.6s | Simple appearance |
| `accordion-down` / `accordion-up` | 0.2s | Disclosure |

Motion communicates a relationship between states. It is never decoration. Interface motion
stays at or below 200ms; content motion may run longer.

## 6. Layout

Container centred, 1.5rem padding, capped at 1200px at `2xl`.

Two shells:

- `PublicLayout` — marketing navigation, footer, cookie consent
- `AppLayout` — collapsible sidebar rail, sticky header carrying global search, the AI
  assistant, theme toggle, notifications and the account menu

The application sidebar groups navigation by function rather than listing thirty-one links
flat. Sidebar tokens are separate from core tokens (`--sidebar-*`) so the rail can be tuned
without touching the page.

## 7. Division accents

Used for a dot, a rule or a small accent. **Never** for a page background, a button or a
surface.

| Division | Accent |
|---|---|
| Care | `#0C447C` |
| Academy | `#B7791F` |
| Cleaning | `#5DCAA5` |
| Security | `#1B2A3C` |

Defined once in `src/lib/divisions.ts`, which is also the single source for division names,
taglines, audiences, assurances and services — driving the navigation mega-menu, the services
pages, the home page and the footer.

## 8. Components

shadcn/ui in the `new-york` style over Radix primitives, 37 components vendored into
`src/components/ui/`. Vendored, not installed, so they are ours to modify.

Rules:

- Extend an existing `ui/` component before adding a new one.
- Never hard-code a colour in a component. Use the token.
- Radix handles the accessibility primitives — keyboard navigation, focus trapping, ARIA. Do
  not replace a Radix primitive with a hand-rolled equivalent.
- Icons: Lucide. One icon set.

## 9. Application icon and manifest

`public/manifest.webmanifest` describes the installable worker app, entering at `/m`.

The manifest is **generated** by `vite-plugin-pwa` from the config in `vite.config.ts`, so it
cannot drift from the build.

Icons: 192 px, 512 px, a separate 512 px **maskable** variant, and a 180 px
`apple-touch-icon.png`. The maskable one is drawn inset into the middle 80% and bleeds to the
edges, because Android crops it to whatever shape the launcher uses. iOS ignores an SVG touch
icon, which is why there is a PNG.

Icons are generated from `public/favicon.svg` — see the script in the Milestone 9 changelog
entry. Regenerate them if the mark changes.

**Two brand defects fixed here:**

- `public/favicon.svg` was still the retired green (`#15803D` / `#22C55E`). Redrawn in navy and
  mint, and the PNGs generated from it.
- The manifest `theme_color` was `#2e7d32`, a green from the same retired palette, while
  `index.html` correctly declared navy. Now navy in both, and generated from one source.

## 10. Retired artefacts

The repository carried **four** competing brand definitions. This was the single clearest sign
that documentation had stopped tracking the code. All are now resolved.

| Artefact | What it claimed | Resolution |
|---|---|---|
| `DESIGN-SYSTEM.md` | "v1 (LOCKED)". Green `#4D7C0F`, Unbounded and Albert Sans | **Deleted.** Superseded by the navy and mint rebrand. Neither font is loaded anywhere in the application |
| `RitaGreenOS.html` | A 46 KB standalone "Feature Specification 2026" with a third green palette and per-division colours | **Deleted.** Its content is superseded by `docs/PRD.md` and `docs/BLUEPRINT.md` |
| `public/manifest.webmanifest` | `theme_color: #2e7d32` | **Corrected** to `#0C447C` |
| `src/index.css` | Navy and mint v2 | **Kept.** This is the truth |

A "LOCKED" document that the application has stopped obeying is worse than no document,
because it is trusted. See `docs/RULES.md` rule 19.
