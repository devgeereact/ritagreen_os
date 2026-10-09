# Website and interface design proposal

## Direction: grounded, personal and organised

Use the same recognisable RitaGreen identity across all surfaces, with different layouts for different tasks. Professional means credible content and predictable behaviour, not adding more visual effects.

| Token | Proposed value | Purpose |
|---|---|---|
| Brand | #33512d | Primary buttons, active navigation and selected accents |
| Deep green | #243B21 | Footer and occasional strong panels |
| Pale sage | #E9EFE6 | Selected rows and quiet section backgrounds |
| Warm paper | #F7F6F2 | Website background and app canvas |
| White | #FFFFFF | Forms and operational surfaces |
| Ink | #202820 | Main text |
| Muted text | #596258 | Supporting text, verify contrast in final context |
| Border | #D9DFD5 | Dividers and form outlines |

Status colours remain distinct from brand colours and always have a text label or icon. Check final text, focus and control contrasts rather than assuming a palette is accessible. Target WCAG 2.2 AA and test keyboard, zoom, screen-reader labels and reduced motion.

Use one readable sans-serif family already available where practical. A restrained serif can be explored for website headlines in the first design review, without committing to a new font dependency. App text: roughly 14–16px with clear line spacing. Website body: roughly 17–18px. Corners: 8–12px, subtle borders, minimal shadows. Motion: brief feedback and simple transitions, no scroll theatre, carousels or animated counters.

Use real team, training and workplace photographs with permission. Do not use identifiable care recipients without appropriate permission, and never imply stock or generated images show actual clients. Avoid generic medical imagery, huge floating cards, excessive pills and abstract gradients. Do not replace an established logo without a separate decision.

## Office app layout

A stable sidebar, compact header, page title, one main action and useful content. Group navigation:

- Today: overview and tasks.
- People: recruitment, staff, compliance and training.
- Work: rota, timesheets, clients/sites and contracts/jobs.
- Business: enquiries, quotes, invoices and reports, permission-dependent.
- Website: pages/settings, news, vacancies, form links and social links.
- Settings: access, company details and operational configuration.

These are navigation groups, not six new modules. Existing screens can be reorganised or linked contextually after usage review. Do not remove a route solely because it is less prominent.

Staff profile tabs: Overview, Documents & checks, Training, Assignments, Employment and Activity. Put emergency contacts in the restricted profile area with a clear emergency-use purpose. Show readiness by service and explain any blocking item.

Rota: week view on desktop, day/list view on phones. Header contains date, service/site filters and Create assignment. Each item shows role, site, time, worker and state. Use an explicit assign/edit action rather than making dragging essential. Preserve entered values when a check fails and explain the correction needed.

Example office composition:

```text
RitaGreen   | Today                         Search   Alerts   Account
            | Thursday, 8 October
Today       | [Unfilled shifts] [Checks to review] [Evidence due]
People      | Today's assignments             [Open rota]
Work        | Time       Site       Worker      Status
Business    | Follow-up queue                 [View all]
Website     | Applicant / enquiry / action / due date
Settings    |
```

## Staff portal

Phone-first navigation: Home, My rota, My records, Requests. Home leads with the next assignment, address/directions, time, site contact and relevant instructions. Use readable times and clear date boundaries for overnight work. Separate an assignment offer from a confirmed booking.

My records shows own details, certificates, training and review status. Requests covers availability, leave, profile corrections and contacting the office. Never show another worker's records. Large tap areas, short forms and a clear saved/failed state matter more than animation. Sensitive records must not be left available through offline caching on a shared phone.

## Website navigation and page tree

Top navigation: Services, About, Work with us, News & advice, Contact. Main action: Request staff. Secondary links: Arrange care and Staff login. Service menu puts Healthcare first, then Cleaning, Security and Training.

```text
Home
Services
  Healthcare
    Healthcare staffing
    Direct care
    Supported living, only if confirmed as an active offer
  Cleaning
  Security
  Training
About
Work with us
  Vacancies / role list
  Role page → approved application destination
News & advice
  Article
Contact / service enquiry
Complaints and feedback
Privacy / cookies / accessibility / terms
Staff login
```

Do not generate a separate page for every location or niche without distinct useful content. Preserve existing valuable URLs where practical; document old-to-new redirects before a domain or URL change.

## Homepage structure and draft copy

1. Simple header, no rotating hero.
2. A strong healthcare-led opening with a real workplace image.
3. Two clear healthcare routes, one for organisations and one for people arranging care.
4. Four services with concise descriptions and useful next actions.
5. How working with RitaGreen works.
6. Evidence-led reasons to choose the company.
7. A real story or approved testimonial, only when available.
8. Recruitment panel and a final service enquiry action.
9. Footer with consistent company identity, contact and social links.

Draft hero: **Care for people. Reliable teams for your organisation.**

Supporting text: **Healthcare staffing and direct care, with cleaning, security and training from one RitaGreen team. Tell us what you need and we will help you take the next step.**

Buttons: **Request healthcare staff** and **Arrange care**. Beneath them: Looking for work? View opportunities.

Draft service hooks:

| Service | Headline | Next action |
|---|---|---|
| Healthcare staffing | Keep your team covered, from planned leave to unexpected gaps. | Discuss staffing |
| Direct care | Start with a conversation about the support you need. | Enquire about care |
| Cleaning | A clear cleaning plan for the places you manage. | Request a cleaning quote |
| Security | Find the right security staff for your site. | Discuss security cover |
| Training | Practical training, with records you can keep up to date. | View training |

These are positioning drafts, not promises of availability. Confirm whether short-notice, hospital, nurse and direct-care services are currently offered before publishing examples.

## Service page pattern

State who the service is for, what is offered, where it operates, how requests are handled, what checks or quality controls apply and what the client should do next. Add a short FAQ addressing real objections and a focused enquiry form.

Healthcare splits staffing from direct care. Cleaning includes landlords, schools and construction, with explicit scope agreed for each contract. Security lists only the assignment types the company supplies. Training lists actual courses and dates or an enquiry action, without an online lesson interface.

## Content and enquiry quality

Audit old content before rewriting. Retain useful facts and a caring voice, remove dated wording, verify every qualification and service claim, and identify the owner of each page. Replace broad '100% checked' statements with accurate role-based process wording supported by operations.

Request-staff form: service, organisation, role/number needed, site area, dates, contact details and notes. Cleaning quote: site type, area, frequency and callback. Care enquiry: contact and broad support request, avoiding clinical detail in a public form. Training enquiry: course interest, group size and dates. Capture only what is needed to route the request.

News & advice is recommended as a small existing CMS feature, not a publishing programme that distracts from operations. Start with three useful evergreen articles if someone owns updates. Otherwise hide the navigation entry until there is worthwhile published content.

## Design approval evidence

Before broad implementation, review three representative mock-ups using fictional records: homepage, office rota/staff profile and mobile next-shift screen. This pack proposes their composition; it does not contain rendered mock-ups. Approve the layout and copy direction first, then test a small implemented slice across desktop and phone before spreading it throughout the system.
