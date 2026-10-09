# RitaGreen 2026 plan

Date: 9 October 2026. Status: **APPROVED by the owner on 9 October 2026 for build.** Supersedes the 8 October draft pack (recoverable from git history).

This pack extends the approved lean CMS launch plan ([`docs/LEAN-CMS-LAUNCH-PLAN.md`](../LEAN-CMS-LAUNCH-PLAN.md), 14 September 2026). That plan still governs the core journey (application, review, verification, onboarding, approval, eligible assignment, expiry follow-up). This pack adds what the owner asked for on 9 October: a rota and staff portal people will actually use, a new look in the company green `#33512d`, a rebuilt public website, recruitment forms linked from the CMS, and clean social profiles.

**Progress is tracked in one place: [`.agent/CURRENT-TASK.md`](../../.agent/CURRENT-TASK.md), section board.** ✅ means merged into the build branch with its checks green. Item-level progress stays in `docs/STATUS.json`.

## In one paragraph

RitaGreen Services Ltd supplies healthcare staff to care homes, hospitals and other providers, offers supported living and home care, runs cleaning contracts, supplies security staff and delivers training. Today the company's people information is scattered across email, forms and spreadsheets. The 2026 build gives the office one simple system (recruit, check, onboard, rota, pay hand-off), gives every worker a phone-friendly portal (shifts, documents, training, leave), and gives clients a professional website with healthcare at the front and clear routes to request staff, arrange care or apply for work.

## Read in this order

| File | What it settles |
|---|---|
| [01 Business and scope](01-BUSINESS-AND-SCOPE.md) | Company facts, the four services, audiences, what is in and out |
| [02 Platform modules](02-PLATFORM-MODULES.md) | The eight app destinations, every feature with its status, keep / switch off / retire |
| [03 Rota and staff portal](03-ROTA-AND-STAFF-PORTAL.md) | Shift lifecycle, rota rules, the four portal tabs, notifications |
| [04 App design](04-APP-DESIGN.md) | Colour, type, components and screen layouts for the office app and portal |
| [05 Website](05-WEBSITE.md) | New look, sitemap, page content and hooks, SEO, old-domain redirects |
| [06 Forms and CMS links](06-FORMS-AND-CMS-LINKS.md) | Careers pages, embedded Google Forms, auto-feed, spreadsheet import, what the CMS publishes |
| [07 Social media](07-SOCIAL-MEDIA.md) | Ready-to-paste profiles for Instagram, Facebook, LinkedIn, TikTok, WhatsApp Community and Google |
| [08 Delivery and consolidation](08-DELIVERY-AND-CONSOLIDATION.md) | Build sections, gates, branch and PR rules, how this pack is folded into the permanent docs and then removed |
| [design-preview.html](design-preview.html) | Visual board: palette, type, components, homepage, office Today screen, staff portal |

## Owner decisions (9 October 2026)

| ID | Decision |
|---|---|
| D01 | One brand, **RitaGreen Services**, with four services named **Healthcare, Cleaning, Security, Training**. Public "Care" and "Academy" labels retire; internal identifiers `care` and `academy` stay |
| D02 | Healthcare staffing leads. Supported living and home care get a page and an enquiry route. The app schedules and checks in care visits; it does not hold care plans or medication records |
| D03 | Recruitment uses one Google Form per role, embedded on the role's website page with an "open in a new tab" fallback. Each response also reaches the app automatically, and an office user can import the response spreadsheet to create or update staff profiles after review |
| D04 | The office app is rebuilt around what the company needs. Extras get a keep, switch-off or retire verdict (file 02) |
| D05 | New design in `#33512d` with warm neutrals, applied to website, office app and staff portal (file 04) |
| D06 | Work is built section by section, each section on its own branch and pull request, tracked with ✅ (file 08) |

Facts still needing the owner are marked `[confirm]` where they appear. Nothing marked `[confirm]` is published until confirmed.

## Rules for this pack

Plain British English. No em dashes. One fact lives in one file; other files link to it. Proposals are labelled as proposals; built behaviour is described only once code and tests show it.
