# Scope and current evidence

## Product contract

RitaGreen needs one manageable operating platform for four services:

| Service | Business scope for this plan | Platform support |
|---|---|---|
| Healthcare, primary offer | Direct care and staffing for care homes, hospitals and other providers | Recruitment, role checks, worker records, sites, rota and a defined direct-care operating boundary |
| Cleaning | Contracts for landlords, schools, construction and commercial premises | Quotes, recurring jobs, teams, site instructions and completion checks |
| Security | Staff supplied to companies and sites | Role-appropriate licence records, assignment instructions, rota and incident reporting |
| Training | Instructor-led or externally delivered courses | Bookings, attendance, assessments, certificates and renewal dates, no LMS |

One person can be a candidate, learner and worker, and work across more than one service. Link roles to the same identity. A person's approval for healthcare must not automatically approve them for security or another assignment.

This is an improvement plan for the existing system, not a framework replacement, a new SaaS business or four separate applications.

## Evidence gathered

Source inspection on 8 October 2026, not a runtime audit:

| Evidence | What it supports | Limit |
|---|---|---|
| package.json, README.md, src/routes/index.tsx | React/Vite SPA, PHP API, public site, office app, worker and client portals | The environment label says Next.js, but the repository evidence does not support that description |
| src/pages/app/ and server/ | Existing surfaces for workers, recruitment, compliance, scheduling, timesheets, clients, contracts, finance, training and content | A screen or endpoint existing does not establish that its whole journey works |
| src/pages/worker/WorkerApp.tsx | Source paths for own shifts, compliance, accepting open shifts and clock events | Availability, leave, full profile editing and end-to-end access need verification |
| src/pages/public/Apply.tsx | Existing short application submits to ats.php | Replacing this with Google Forms without an intake process could lose the existing direct ATS capture |
| server/blog.php | Draft/published content and a public published-post read path | Live publishing, sanitisation, permissions and website rendering need tests |
| src/lib/company.ts, src/lib/divisions.ts | Central but code-held company copy, contacts, services and social URLs | Current URLs, corporate identity and service claims are unverified |
| docs/DESIGN_SYSTEM.md | Current documented navy and mint direction | The requested green is a proposed replacement, not the current documented standard |
| CLAUDE.md, docs/ROADMAP.md | Local progress and migrations are documented separately from production | No live database read was made; deployment status remains unverified |

The initial Git working tree was clean. No secret configuration, employee records or production database were read.

## Public website findings

The [original homepage](https://ritagreenhealthcareservices.com/) mixes care-facing language with staffing and recruitment. Recruitment currently asks applicants to download forms and email them back. The [services page](https://ritagreenhealthcareservices.com/our-services/) discusses both staffing and direct care, and retains pandemic-era wording. Recommendations: separate audiences, replace the download-and-email first step, review dated content and retain the company's human care voice.

The supplied [Google profile link](https://share.google/FNAsbZ6C7mEW3rVvL) could not be resolved. Its business name, categories, address, reviews, images and ownership have not been verified. Do not invent a rating or quote a review. The supported-living page also failed to load in this research session.

## Direct care boundary

Direct care is explicitly included. Before implementation, establish which services RitaGreen actually delivers, who is responsible for care plans, and what approved system currently holds clinical or care-delivery records.

Recommended first boundary: this app handles workforce, scheduling, operational visit records, concerns and restricted links to the approved care record system. It must not silently become the only clinical record system. If RitaGreen has no suitable care-delivery system, choosing one or defining a separately approved minimum care-record scope is a dependency before moving direct-care delivery into this app. Medication administration, clinical assessments and electronic care plans are outside this polish phase.

Registration claims must match the legal entity and activities actually registered. CQC explains that providers carrying on regulated activities need the appropriate registration: [personal care registration guidance](https://www.cqc.org.uk/guidance-regulation/providers/registration/personal-care-registration-homecare-agencies/you-apply). This plan does not establish RitaGreen's registration status.

## Assumptions and unresolved facts

Assume London and nearby areas only as a draft, based on existing content. Confirm service coverage, legal and trading names, correct domain, office address, contact details, hours, authorised urgent-cover response and real training offers. Do not carry across universal DBS, accreditation, guaranteed interview, round-the-clock availability or placement claims without evidence.

The repository uses Care and Academy internally. Recommend Healthcare and Training as public labels, while retaining internal identifiers unless a separately reviewed change is needed. Confirm whether Academy is an established trading brand.

No staff count, budget or delivery capacity was supplied. Phase gates are proposed instead of an unreliable fixed completion date.
