# 01 Business and scope

## The company

| Fact | Value | Source |
|---|---|---|
| Legal name | RitaGreen Services Ltd | `src/lib/company.ts`; Google profile resolves to "RITAGREEN SERVICES LTD" |
| Company number | 12528439 | `src/lib/company.ts` |
| Trading brand | RitaGreen Services (decision D01) | Owner, 9 October 2026 |
| Main telephone | 0203 105 2218 | `company.ts` and the original website |
| Urgent cover line | +44 (0)7377 563859 `[confirm]` | `company.ts` |
| Email | hello@ritagreenservices.com `[confirm mailbox is live]` | `company.ts`; the original site still shows a Gmail address, which retires |
| Office hours | Monday to Friday, 9am to 5pm `[confirm]` | `company.ts` |
| Area | London and surrounding boroughs, based in south-east London `[confirm]` | `company.ts`; original site refers safeguarding to Lewisham Council |
| Registered address | `[confirm]` | Still a TODO in `company.ts` |
| CQC registration for supported living or home care | `[confirm]` | Not established. No regulated claim is published until confirmed |
| Domains | ritagreenservices.com (new, primary); ritagreenhealthcareservices.com (old, to redirect) | `CLAUDE.md`; original site |

## The four services

Healthcare leads everything: navigation order, homepage, social bios.

| Service | Who buys | What RitaGreen supplies | Roles | Checks the system must hold |
|---|---|---|---|---|
| **Healthcare** (primary) | Care homes, nursing homes, NHS and private hospitals, day centres, supported living providers; families arranging care | Staffing: planned and short-notice cover. Second: supported living and home care `[confirm current scope]` | Care assistant, support worker, senior carer, registered nurse (RGN, RMN) | Right to work, enhanced DBS (adult and/or child workforce as the role requires), references, Care Certificate or equivalent, mandatory training, NMC PIN for nurses |
| **Cleaning** | Landlords and letting agents, schools, construction sites, offices and commercial premises, care settings | Contract cleaning: regular, communal, void and end-of-tenancy, builders' cleans, infection-control cleaning | Cleaner, team leader, supervisor | Right to work, references, COSHH awareness, DBS where the site requires it (schools, care settings) |
| **Security** | Companies, sites, events, residential blocks | Licensed officers: static guarding, patrols, door supervision, concierge | Security officer, door supervisor, supervisor | Right to work, SIA licence of the correct type checked against the public register, references, first aid where required |
| **Training** | Care providers, RitaGreen's own staff, individuals | Instructor-led courses: Care Certificate, mandatory and refresher training `[confirm course list]` | Trainer | Trainer qualifications; course attendance and certificates for attendees |

Training is delivered in person or by an external provider. The system records bookings, attendance, certificates and renewal dates. It is **not** a learning platform: no lessons, quizzes, video or learner progress (launch plan, Phase 6 boundary).

## Who uses what

| Audience | Uses | Main need |
|---|---|---|
| Client organisations | Website, enquiry forms, optional client portal later | Get reliable staff quickly and know they are checked |
| Families arranging care | Website, care enquiry | A human conversation, not a sales form |
| Job seekers | Website careers pages and embedded forms | Find a role, apply in minutes on a phone |
| Workers | Staff portal at `/m` | Know where to be, keep documents in date, ask for leave |
| Office team | Office app at `/app` | One list of what needs doing today; one record per person |
| Owner | Office app, Today and Reports | See blockers, approvals and expiring checks at a glance |

## One person, many roles

One person can be an applicant, a worker in more than one service and a training attendee. They exist once in `people` and every record links to them (`CLAUDE.md`, "A person exists once"). Approval for one service never approves them for another.

## In scope for 2026

1. Finish the launch plan journey (Phases 3 to 5 remain; see file 08).
2. Rota and staff portal (file 03).
3. New design across all surfaces (file 04).
4. Rebuilt website with CMS-managed vacancies, forms, company details and social links (files 05, 06).
5. Recruitment intake from Google Forms, including spreadsheet import (file 06).
6. Social profile copy and Google Business Profile copy (file 07). Account changes are made by the owner.

## Out of scope

Learning platform features, care plans and medication records, native app-store apps, a payroll engine beyond the existing pay-run export, route optimisation, live location tracking, automatic social posting, a page builder, multi-company or multi-tenant features, AI scoring of candidates.
