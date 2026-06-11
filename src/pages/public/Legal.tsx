import { PageHeader, Section } from "@/components/public/section"
import { COMPANY } from "@/lib/company"

type LegalKind = "privacy" | "terms" | "cookies"

type LegalDoc = {
  title: string
  intro: string
  sections: { h: string; p: string; list?: string[] }[]
}

const CONTENT: Record<LegalKind, LegalDoc> = {
  privacy: {
    title: "Privacy policy",
    intro: `How ${COMPANY.legalName} collects, uses and protects personal data, under UK GDPR and the Data Protection Act 2018.`,
    sections: [
      {
        h: "Who we are",
        p: `${COMPANY.legalName} (Company No. ${COMPANY.companyNo}) is the data controller for the personal data described in this policy. To contact us about your data, email ${COMPANY.email}.`,
      },
      {
        h: "Who this applies to",
        p: "This policy covers everyone we interact with:",
        list: [
          "Clients and their contacts",
          "Care service users, where we hold their data",
          "Job applicants and trainees",
          "Our workers",
          "Visitors to this website",
        ],
      },
      {
        h: "What we collect",
        p: "We collect only what we need to run our services and meet our duties:",
        list: [
          "Clients & contacts: contact and identity details (name, address, email, phone)",
          "Workers & applicants: identity, right-to-work, DBS, qualifications, references, payroll and role-related health information",
          "Service users: care-related data, including health and care needs, where we provide or manage care",
          "Website visitors: usage and cookie data, as set out in our cookie policy",
        ],
      },
      {
        h: "Special category data",
        p: "To provide care and employ workers safely, we process special category data such as health information and information about criminal convictions (DBS). We do this under UK GDPR Article 9 and the Data Protection Act 2018 — including employment, social-care and safeguarding grounds — and we apply extra safeguards.",
      },
      {
        h: "Why we use it & our lawful basis",
        p: "We use personal data to provide and manage services; to vet, employ and pay workers; to deliver and manage care; for safeguarding and DBS checks; to meet legal and regulatory duties; and, for business contacts, to market to you with an opt-out. Our lawful bases include contract, legal obligation, legitimate interests, vital interests and the social-care conditions.",
      },
      {
        h: "Who we share it with",
        p: "We share data only as needed — for example a worker's name and relevant clearances with a client. We may share with the DBS, regulators (such as the CQC and SIA), HMRC and our insurers where required, and with trusted suppliers (payroll, IT, training) under written data-processing terms. We do not sell personal data, and any transfer outside the UK is protected by appropriate safeguards.",
      },
      {
        h: "How long we keep it",
        p: "We keep personal data only as long as needed for the purpose and to meet legal duties — for example worker and payroll records for around six years, DBS records per DBS guidance, and care records per sector retention rules. After that we securely delete or anonymise it.",
      },
      {
        h: "Your rights",
        p: "Under UK GDPR you can access a copy of your data, correct it, ask us to erase it where the law allows, restrict or object to certain processing, ask for portability, or withdraw consent. To exercise a right, email us and we'll respond within one month. You can also complain to the Information Commissioner's Office (ico.org.uk).",
      },
      {
        h: "Keeping data safe",
        p: "We use access controls, encryption where appropriate, staff training and secure disposal to protect personal data, and we keep these measures under review.",
      },
    ],
  },
  terms: {
    title: "Terms & conditions",
    intro: `The standard terms on which ${COMPANY.legalName} supplies care, training, cleaning and security.`,
    sections: [
      {
        h: "About these terms",
        p: `These terms apply to all services supplied by ${COMPANY.legalName} (Company No. ${COMPANY.companyNo}). By accepting a quotation or instructing us, you (the Client) agree to them. They sit alongside any Service Agreement, which takes precedence if they conflict.`,
      },
      {
        h: "Quotations & bookings",
        p: "Quotations are valid for 30 days unless stated otherwise. A booking is confirmed when you accept in writing and we acknowledge it. We'll tell you promptly if we can't supply a confirmed booking and offer a replacement where possible.",
      },
      {
        h: "Our workers",
        p: "We vet every worker before assignment: enhanced DBS where required, references and right-to-work verification. Workers hold the qualifications and licences appropriate to their role — for example SIA licences for security and Care Certificate training for care. We pay, insure and manage our workers; they are not your employees unless agreed in writing.",
      },
      {
        h: "Charges, VAT & payment",
        p: "Charges are as set out in the quotation. Invoices are payable within 30 days of the invoice date. Overtime, bank holidays and unsociable hours are charged at the rates shown in the quotation. Late payment may incur interest and reasonable recovery costs under the Late Payment of Commercial Debts (Interest) Act 1998.",
      },
      {
        h: "Cancellation & changes",
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
        h: "Liability",
        p: "We don't exclude liability for death or personal injury caused by our negligence, fraud, or anything that can't be excluded by law. Otherwise our total liability for any claim is limited as set out in your agreement, and we're not liable for indirect or consequential loss, or loss you could have reduced by telling us sooner.",
      },
      {
        h: "Insurance",
        p: "We hold public liability and employers' liability insurance. Certificates and cover limits are available on request.",
      },
      {
        h: "Complaints & governing law",
        p: `Tell your named contact or email ${COMPANY.email}. We acknowledge complaints within two working days and aim to resolve them within ten. These terms are governed by the law of England and Wales and subject to the exclusive jurisdiction of its courts.`,
      },
    ],
  },
  cookies: {
    title: "Cookie policy",
    intro: "How this website uses cookies and similar technologies.",
    sections: [
      {
        h: "What cookies are",
        p: "Cookies are small files stored on your device that help a website work and remember your preferences. Similar technologies, like local storage, do much the same job.",
      },
      {
        h: "How we use them",
        p: "We use only the cookies needed to run the site and keep it secure, and — where you agree — cookies that help us understand how the site is used so we can improve it. We don't use cookies to build advertising profiles.",
      },
      {
        h: "Types of cookie we use",
        p: "These fall into two groups:",
        list: [
          "Essential: needed for the site to function, such as security and remembering your session. These are always on.",
          "Analytics (optional): help us see which pages are useful and where to improve. Used only with your agreement.",
        ],
      },
      {
        h: "Managing cookies",
        p: "You can control or delete cookies through your browser settings, and set most browsers to block them. Blocking some cookies may affect how parts of this site work.",
      },
    ],
  },
}

export default function Legal({ kind }: { kind: LegalKind }) {
  const c = CONTENT[kind]
  return (
    <>
      <PageHeader eyebrow="Legal" title={c.title} intro={c.intro} />
      <Section className="max-w-3xl">
        <p className="rounded-lg border border-border bg-secondary/40 p-4 text-sm text-muted-foreground">
          Last updated{" "}
          {new Date().toLocaleDateString("en-GB", { month: "long", year: "numeric" })}. This is a
          plain-English summary and a starting template — it should be reviewed by a qualified UK
          solicitor before launch, and placeholders (registered office, ICO reference, exact figures)
          confirmed.
        </p>
        <div className="mt-8 space-y-8">
          {c.sections.map((s) => (
            <div key={s.h}>
              <h2 className="text-lg font-semibold">{s.h}</h2>
              <p className="mt-2 text-muted-foreground">{s.p}</p>
              {s.list && (
                <ul className="mt-3 space-y-2">
                  {s.list.map((li) => (
                    <li
                      key={li}
                      className="flex items-start gap-2 text-sm text-muted-foreground"
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
      </Section>
    </>
  )
}
