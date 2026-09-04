import { Link } from "react-router-dom"
import { PageHeader, Section } from "@/components/public/section"
import { COMPANY, LEGAL_REVIEW_PENDING } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

type LegalKind = "privacy" | "terms" | "cookies"

type LegalDoc = {
  title: string
  intro: string
  /** Bumped when the words change, not when the page renders. */
  version: string
  /** The date this version took effect, written down. See the note below. */
  effective: string
  sections: { h: string; p: string; list?: string[] }[]
}

// These are fixed strings on purpose.
//
// The previous version printed `new Date().toLocaleDateString(...)`, so the page
// always claimed it had been updated this month — whichever month you happened
// to read it in, and however long the words had actually been sitting there. A
// policy's date is a fact about the document, not about the visitor's clock.
// Change them when you change the text, in the same commit.

// The registered office and ICO reference are owner-supplied. Until they are
// set, say so in place of the value rather than writing round the gap.
const office = COMPANY.registeredAddress || "[registered office address to be confirmed]"
const ico = COMPANY.icoNumber
  ? `We are registered with the Information Commissioner's Office under reference ${COMPANY.icoNumber}.`
  : "Our ICO registration reference will be published here once confirmed."

const CONTENT: Record<LegalKind, LegalDoc> = {
  privacy: {
    title: "Privacy notice",
    intro: `How ${COMPANY.legalName} collects, uses and looks after personal information, under UK GDPR and the Data Protection Act 2018.`,
    version: "2.0",
    effective: "4 September 2026",
    sections: [
      {
        h: "Who we are, and how to reach us",
        p: `${COMPANY.legalName} (Company No. ${COMPANY.companyNo}), registered office ${office}, is the data controller for the information described here. ${ico} For anything about your information, email ${COMPANY.privacyEmail} or use our data request form.`,
      },
      {
        h: "Who this notice is about",
        p: "We hold information about several different groups of people, and what we hold differs a lot between them. The sections below are grouped that way so you can read the part that applies to you:",
        list: [
          "People who visit this website",
          "People who contact us or ask for a quote",
          "People who apply to work with us, or to train with our academy",
          "People who work for us",
          "Clients and the people who work for them",
          "People we provide or manage care for",
        ],
      },
      {
        h: "If you visit this website",
        p: "The site loads entirely from our own servers. There is no analytics, no advertising, no tracking pixels, no social media embeds and no third-party fonts or scripts, so browsing the site sends nothing about you to anyone else. It stores a small number of things in your browser to work at all — your sign-in if you have one, your light or dark preference, and the fact you have seen our storage notice. Our web server keeps ordinary request logs, and the site sits behind Cloudflare, which handles the connection. The cookie and storage notice lists every item by name.",
      },
      {
        h: "If you contact us or ask for a quote",
        p: "We collect your name, email address, phone number, which service you are asking about and whatever you write in the message, including anything you tell us about a site or a start date. We use it to answer you, to prepare a quote and to keep track of the enquiry. Our reason for doing so is our legitimate interest in responding to people who approach us about our services, and, once you engage us, performing the contract. Answering your enquiry is not marketing and does not depend on the optional marketing box on those forms.",
      },
      {
        h: "Marketing, and how to stop it",
        p: "We only send marketing email to people who have ticked the optional box asking for it. It is never bundled into anything else, it is never ticked in advance, and it never affects whether we answer your enquiry. Every marketing email carries an unsubscribe link that works without signing in, and we keep a record of the opt-out so a later import cannot put you back on the list. Messages about a booking, a shift, an invoice or an application you have sent us are not marketing and will still reach you.",
      },
      {
        h: "If you apply to work or train with us",
        p: "We collect your name, contact details, the role you are after, the division, and whatever you tell us about your experience and availability. Some applications reach us through an online form hosted by Google, in which case a copy of your answers exists there as well as here. We use this to assess your application, to contact you about it and, if you join us, to start your employment record. We ask you to confirm you are happy for us to hold and use these details for that purpose; where you go on to work for us, our reason shifts to taking steps to enter into a contract with you and meeting our legal duties as an employer.",
      },
      {
        h: "If you work for us",
        p: "An employment record is much more than contact details. Depending on your role we hold:",
        list: [
          "Identity and contact details, and an emergency contact",
          "Right-to-work checks: how the check was done, when, by whom, the outcome and when it is due again",
          "DBS checks and their status, and SIA licence details for security roles",
          "Qualifications, training and certificates",
          "References we take up, which include your referees' own contact details",
          "Pay rate, pay type, timesheets and holiday entitlement",
          "Holiday and absence records, including sickness, and any return-to-work discussion",
          "Assignments, shifts, clock-in and clock-out times, and incidents you were involved in",
        ],
      },
      {
        h: "Information that needs extra care",
        p: "Some of the above is special category information or information about criminal convictions — health and sickness details, DBS results, and anything recorded in a safeguarding matter. We process it because employment law, social security and social protection law, and safeguarding require us to, using the conditions in Article 9 of the UK GDPR and Schedule 1 of the Data Protection Act 2018. Inside the system it sits behind separate permissions rather than being visible to everyone who can open your record: absence reasons and return-to-work notes, for instance, are not shown to a manager arranging cover, who sees only that you are away and for how long.",
      },
      {
        h: "Location while you are working",
        p: "The worker app records where you are when you clock in, when you clock out, and if you raise a panic alert. Clock-in and clock-out positions are deliberately stored roughly, to about 110 metres, which is enough to show you were at the site and not enough to be a record of your movements. A panic alert keeps full precision, because the point of it is that somebody can find you. Your phone will ask before sharing location, and if you say no, clocking in still works. We use this to confirm attendance, to resolve disputes about hours and to respond to an alert.",
      },
      {
        h: "Decisions the system makes on its own",
        p: "Our system works out, automatically, whether a worker meets the requirements for a particular kind of work — the right-to-work check, the DBS, the SIA licence and any credential that has expired. If a mandatory requirement is missing or out of date, the system refuses the assignment, and it re-checks everyone daily without anyone pressing a button. Approved absence is one of the things it looks at. This means a computer can stop you being given a shift. It is a rules check against fixed requirements, not a judgement about you, the reason is always recorded and always visible to you, and you can ask a person to look at it — email us and we will.",
      },
      {
        h: "If you are a client, or work for one",
        p: "We hold your name, role, contact details, your sites and addresses, the bookings and contracts we agree, and the invoices between us. We use it to provide the service, to run the account and to get paid. Our reasons are performing our contract with you, meeting our legal obligations around records and tax, and our legitimate interest in managing the relationship. Through the client portal you can see the workers assigned to you, their role, and whether their required clearances are in date — you do not see their compliance documents, their pay, their absence or anything else about them.",
      },
      {
        h: "If we hold care records about you",
        p: "Where we provide or manage care, we hold care-related information, which will include health information and details of your needs. We use it to deliver care safely, to meet our duties to the Care Quality Commission and to safeguard people. This is special category information and is treated as described above.",
      },
      {
        h: "Who else sees your information",
        p: "We do not sell personal information. It reaches other organisations in two ways. Sometimes we have to share it — with the Disclosure and Barring Service, the Care Quality Commission, the Security Industry Authority, HMRC, our insurers, or a client who needs to know a worker's clearances are valid. Sometimes an organisation processes it on our behalf, under written terms:",
        list: [
          "Our web and email hosting provider, which is where the system and the mailboxes run",
          "Cloudflare, which sits in front of the website",
          "Google, where an online application form is used, and for staff who sign in with a Google account",
          "Twilio, if we send you a text or WhatsApp message about a shift",
          "Stripe and GoCardless, if you pay us by card or Direct Debit — card and bank details go to them, never to us",
          "An AI service, where a member of staff asks it to summarise a document; this is used by staff, not applied to your record automatically",
        ],
      },
      {
        h: "Sending information outside the UK",
        p: "Some of those providers are based outside the UK, mainly in the United States. Where that happens the transfer is covered by the safeguards in the UK data protection rules, through the terms we hold with each provider. If you want to know which provider handles a particular thing, and on what basis, ask us and we will tell you.",
      },
      {
        h: "How long we keep it",
        p: "We are not able to give a single answer, because different records have different requirements — employment and payroll records, right-to-work checks, absence records, incident records and training records are all governed by different rules, and some of those rules change. So we hold a written retention policy for each type of record rather than a rule of thumb, we review each type on a schedule, and we archive or securely delete rather than keeping things indefinitely. We are in the middle of setting those periods with advice; ask us about a specific record and we will tell you where it stands.",
      },
      {
        h: "Keeping it safe",
        p: "Everything travels over an encrypted connection. Passwords are hashed and never stored in a readable form, sessions can be revoked from any device, and two-factor authentication is available and can be required. Documents are held outside the reachable part of the server and streamed only to people entitled to open that particular file. Sensitive categories sit behind separate permissions. Changes to records are written to an audit log, deleting an employment record needs a second person to approve it, and every export of personal information is logged against the person who took it and why.",
      },
      {
        h: "Your rights",
        p: "You can ask us for a copy of what we hold, to correct something wrong, to delete it, to restrict or object to what we are doing with it, for a portable copy, or to withdraw consent you gave earlier. Some of these have limits — we cannot delete a record we are legally required to keep, and we will tell you plainly when that applies rather than quietly doing nothing. Use the data request form or email us, and we will confirm who you are before we share anything. We will never ask you to email us a passport or driving licence. If you are unhappy with how we handle it you can complain to the Information Commissioner's Office at ico.org.uk.",
      },
      {
        h: "Children",
        p: "This website and our services are meant for adults. We do not knowingly collect information about children through this site. Where a care service involves a young person, that information is held under our care records and the arrangements agreed with the commissioning body.",
      },
      {
        h: "Changes to this notice",
        p: "When we change this notice we change the version and the date at the top of the page. Where a change materially affects you, we will do more than that — we will tell you.",
      },
    ],
  },
  terms: {
    title: "Terms and conditions",
    intro: `The standard terms on which ${COMPANY.legalName} supplies care, training, cleaning and security.`,
    version: "1.1",
    effective: "4 September 2026",
    sections: [
      {
        h: "About these terms",
        p: `These terms apply to all services supplied by ${COMPANY.legalName} (Company No. ${COMPANY.companyNo}), registered office ${office}. By accepting a quotation or instructing us, you (the Client) agree to them. They sit alongside any Service Agreement, which takes precedence if they conflict.`,
      },
      {
        h: "Quotations and bookings",
        p: "Quotations are valid for 30 days unless stated otherwise. A booking is confirmed when you accept in writing and we acknowledge it. We'll tell you promptly if we can't supply a confirmed booking and offer a replacement where possible.",
      },
      {
        h: "Our workers",
        p: "We vet every worker before assignment: enhanced DBS where required, references and right-to-work verification. Workers hold the qualifications and licences appropriate to their role — for example SIA licences for security and Care Certificate training for care. Our system checks those are in date before an assignment is made and refuses one where a mandatory requirement is missing or expired. We pay, insure and manage our workers; they are not your employees unless agreed in writing.",
      },
      {
        h: "Charges, VAT and payment",
        p: "Charges are as set out in the quotation. Invoices are payable within 30 days of the invoice date. Overtime, bank holidays and unsociable hours are charged at the rates shown in the quotation. Late payment may incur interest and reasonable recovery costs under the Late Payment of Commercial Debts (Interest) Act 1998.",
      },
      {
        h: "Cancellation and changes",
        p: "You may cancel a confirmed shift or visit with 24 hours' notice at no charge; inside that window we may charge up to 50%. For managed contracts, the notice period in the Service Agreement applies.",
      },
      {
        h: "Direct engagement of our workers",
        p: "To protect our investment in recruiting, training and vetting, you agree not to employ or engage a RitaGreen worker directly during an assignment, or for six months afterwards, except through our permanent-placement terms and fee.",
      },
      {
        h: "Your obligations",
        p: "Provide a safe environment, safe access and the information our workers need; comply with health and safety law and, in care settings, your CQC obligations; supervise the work to the extent agreed and report concerns promptly.",
      },
      {
        h: "Data protection between us",
        p: "Each of us is responsible for the personal information we hold in our own right. Where we assign a worker to you, we share only what you need to know: who is coming, their role, and that the clearances their role requires are valid. Where you give us personal information about the people you support so that we can deliver a service, we handle it as set out in our privacy notice. Neither of us should pass the other's information on without a reason and a basis for doing so.",
      },
      {
        h: "Liability",
        p: "We don't exclude liability for death or personal injury caused by our negligence, fraud, or anything that can't be excluded by law. Otherwise our total liability for any claim is limited as set out in your agreement, and we're not liable for indirect or consequential loss, or loss you could have reduced by telling us sooner.",
      },
      {
        h: "Insurance",
        p: "We hold public liability and employers' liability insurance. Certificates and cover limits are available on request.",
      },
      {
        h: "Complaints and governing law",
        p: `Tell your named contact or email ${COMPANY.email}. We acknowledge complaints within two working days and aim to resolve them within ten. These terms are governed by the law of England and Wales and subject to the exclusive jurisdiction of its courts.`,
      },
    ],
  },
  cookies: {
    title: "Cookie and storage notice",
    intro: "Exactly what this website puts in your browser, and why.",
    version: "2.0",
    effective: "4 September 2026",
    sections: [
      {
        h: "The short version",
        p: "This site sets no advertising cookies, runs no analytics and loads nothing from another company's servers — no third-party fonts, no maps, no video embeds, no social widgets, no tracking pixels. Nothing here follows you to another website. That means there is nothing optional to accept or refuse, which is why you are shown a notice rather than a choice.",
      },
      {
        h: "What is actually stored",
        p: "Four things, all first-party, all set by this site and readable only by it:",
        list: [
          "rgos_token — your sign-in, in local storage, if you have an account. Removed when you sign out. Without it you cannot stay signed in.",
          "rg-storage-notice — that you have seen this notice, and which version, in local storage. It is why the notice does not reappear on every page.",
          "theme — whether you chose light or dark, in local storage.",
          "sidebar_state — whether the menu in the staff area is open or collapsed. A cookie, kept for 7 days, holding only true or false.",
        ],
      },
      {
        h: "Offline data in the worker app",
        p: "If you install the worker app, it keeps a copy of your own profile, your compliance status and your upcoming shifts in the browser so the app still shows them when you have no signal. That copy is refreshed when you are online and expires after 12 hours, and it is cleared when you sign out.",
      },
      {
        h: "Server logs",
        p: "Separately from your browser, our web server keeps ordinary request logs, and we record the IP address alongside a small number of actions — a form submission, a sign-in, an administrative change — so that abuse can be spotted and so an audit trail means something. That is on our servers, not on your device.",
      },
      {
        h: "Managing it",
        p: "You can clear or block all of this in your browser settings. If you clear the sign-in item you will be signed out; if you clear the others you will lose a preference and see this notice again. Nothing you clear will stop the site working. You can reopen the storage notice at any time from the link in the footer.",
      },
      {
        h: "If this changes",
        p: "If we ever add something optional — analytics, for instance — this notice gets a new version, you will be asked again, and nothing optional will run until you have said yes.",
      },
    ],
  },
}

export default function Legal({ kind }: { kind: LegalKind }) {
  const c = CONTENT[kind]
  useSeo({ title: c.title, description: c.intro, path: `/${kind}` })
  return (
    <>
      <PageHeader eyebrow="Legal" title={c.title} intro={c.intro} />
      <Section className="max-w-3xl">
        <p className="text-sm text-muted-foreground">
          Version {c.version} · Effective {c.effective}
        </p>

        {LEGAL_REVIEW_PENDING && (
          <p className="surface mt-4 border-l-2 border-primary bg-soft-mesh p-5 text-sm leading-relaxed text-muted-foreground">
            This document describes what we actually do and is written to be read, not to be
            skimmed past. It is with our solicitors for review, and we will say so here when
            that is done.
          </p>
        )}

        <div className="mt-10 space-y-10">
          {c.sections.map((s, i) => (
            <div key={s.h} className={i > 0 ? "border-t border-border/60 pt-10" : undefined}>
              <h2 className="flex items-baseline gap-3 text-lg font-semibold">
                <span className="font-mono text-sm font-bold text-primary">{String(i + 1).padStart(2, "0")}</span>
                {s.h}
              </h2>
              <p className="mt-3 leading-relaxed text-muted-foreground">{s.p}</p>
              {s.list && (
                <ul className="mt-4 space-y-2">
                  {s.list.map((li) => (
                    <li
                      key={li}
                      className="flex items-start gap-3 text-sm leading-relaxed text-muted-foreground"
                    >
                      <span className="mt-2 size-1.5 shrink-0 rounded-full bg-primary" />
                      {li}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>

        {kind === "privacy" && (
          <div className="surface mt-12 flex flex-col items-start gap-3 p-6 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-muted-foreground">
              Want a copy of what we hold, or something corrected or deleted?
            </p>
            <Link
              to="/data-request"
              className="shrink-0 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              Ask us about your data
            </Link>
          </div>
        )}
      </Section>
    </>
  )
}
