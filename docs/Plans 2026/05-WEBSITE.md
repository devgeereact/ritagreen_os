# 05 Website

## Goal

A client should know within five seconds that RitaGreen supplies healthcare staff, and should reach "Request staff" in one tap. A job seeker should reach the right application form in two taps. Cleaning, security and training are clearly offered without crowding healthcare.

## Look and feel

Warm paper background, deep green blocks for emphasis, serif headlines, generous spacing, real photographs. Tokens and type come from file 04.

Photography: commission a half-day shoot (staff in uniform, a training session, the office, a cleaning team at work, an officer on site) with signed consent. Until then use neutral imagery that never implies it shows RitaGreen clients or care recipients; the five current Unsplash images in `public/images/divisions/` are replaced once real photos exist.

Logo: no logo file exists (the mark is an inline SVG in `src/components/brand/Logo.tsx`). S6 ships a clean "RitaGreen" wordmark in `#33512d`. A professionally designed logo is a separate owner decision.

## Sitemap

```text
/                       Home
/healthcare             Healthcare (staffing overview)
/healthcare/staffing    Staffing for care homes and hospitals
/healthcare/supported-living   Supported living and home care
/cleaning               Cleaning (landlords and property, schools, construction, commercial)
/security               Security
/training               Training (courses, group bookings, verify a certificate)
/careers                Current roles
/careers/:slug          Role page with embedded application form (file 06)
/about                  Our story, how we check and train staff, standards
/news                   News and advice (CMS posts)
/news/:slug             Article
/contact                Contact and general enquiry
/request                Request staff (main call to action)
/links                  Link-in-bio page for social profiles (CMS managed)
/verify, /verify/:id    Certificate check (kept)
/privacy, /terms, /cookies, /data-request   Legal (kept)
/login                  Staff login
```

Old paths keep working through redirects: `/services` and `/services/:slug` to the matching service page, `/sectors` and `/accreditations` to `/about`, `/resources` and `/resources/:slug` to `/news`, `/apply` to `/careers`, `/quote` to `/request?type=cleaning`. The static `public/sitemap.xml` is replaced by one generated at build time from routes, live posts and vacancies.

Navigation: Healthcare, Cleaning, Security, Training, Careers, About, Contact. Primary button "Request staff". Text link "Staff login".

## Homepage

Draft copy. Every factual claim is checked against file 01 before publishing.

1. **Hero.** Headline: *Reliable care staff when your rota can't wait.* Supporting line: *Carers, support workers and nurses for care homes, hospitals and supported living across London. Plus cleaning, security and training from the same team.* Buttons: **Request staff** and **Arrange care**. Under them: *Looking for work? See current roles.*
2. **Two healthcare routes.** "For care providers: planned cover and short-notice gaps" and "For families: supported living and care at home `[confirm]`".
3. **Four services**, each with a hook and a link:
   - Healthcare: *Cover for planned leave and last-minute gaps.*
   - Cleaning: *Scheduled cleaning for properties, schools and sites.*
   - Security: *Trained, licensed officers for your premises.*
   - Training: *Mandatory and refresher courses for care teams.*
4. **How it works.** Tell us what you need. We match checked, trained staff. They arrive briefed for your site.
5. **Why RitaGreen.** Every worker's checks are tracked and in date before they are booked (true once S2 to S3 are live). One contact for four services. Our own training team. Use specifics, never "100% compliant" or "24/7" unless confirmed.
6. **Testimonial** only when a real, permissioned one exists (Website area, testimonials).
7. **Careers band** on deep green: *Join a team that looks after its people.* Button **See current roles**.
8. **Latest news**, three posts, hidden when there are none.
9. **Footer** from CMS company details: address, phone, email, hours, social links, company number, legal links.

## Service page pattern

Same structure on every service page so visitors learn it once:

| Block | Content |
|---|---|
| Opening | Who it is for and what we supply, in two sentences |
| What we provide | Concrete list (from file 01) |
| How it works | Three steps, with realistic response times `[confirm]` |
| Checks and training | What every worker on this service has been checked for |
| Who we work with | Sectors served, no client logos without permission |
| Questions | Four to six real FAQs |
| Enquiry | A short form for that service |

Draft hooks for service openings:

- Staffing: *Keep your rota covered, from planned leave to the call at 6am.*
- Supported living and home care: *Start with a conversation about the support you or your family member needs.*
- Cleaning: *A clear cleaning plan for the places you manage, agreed in writing and checked on every visit.*
- Security: *Licensed officers who know your site before their first shift.*
- Training: *Practical training, delivered by people who have done the job, with records you can rely on.*

## Enquiry forms and where they go

| Form | Fields | Endpoint |
|---|---|---|
| Request staff | Organisation, contact, service, role and number, site area, dates and times, notes | `server/bookings.php` |
| Cleaning quote | Organisation, contact, site type, size, frequency, start date | `server/quotes.php` intake (today `/quote` wrongly posts to `contact.php`) |
| Care enquiry | Name, contact, who the care is for, broad need, preferred callback time. No medical detail on a public form | `server/contact.php` with type "care" |
| Training enquiry | Course, group size, preferred dates, contact | `server/contact.php` with type "training" |
| General contact | Name, contact, message | `server/contact.php` |

Every enquiry appears in the office app, in Today, with an owner.

## SEO and search presence

Per-page titles and descriptions through `src/hooks/use-seo.ts`. Structured data: `EmploymentAgency` and `LocalBusiness` on the homepage, `Service` on service pages, `JobPosting` on role pages, `Article` on posts. Old domain `ritagreenhealthcareservices.com` redirects page by page (301) to the new pages once the owner confirms control of that domain. Google Business Profile is updated with the new website link (file 07).
