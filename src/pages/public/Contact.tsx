import { Link } from "react-router-dom"
import { PageHeader, Section } from "@/components/public/section"
import { GeneralEnquiryForm } from "@/components/public/forms/ContactForms"
import { useCompanyDetails } from "@/lib/company"
import { useSeo } from "@/hooks/use-seo"

// /contact: how to reach the office, and the general message form
// (contact.php, type general). Staff, quotes, care and training each have their
// own focused form on /request, linked from here so nobody has to describe a
// shift in a free-text box.
export default function Contact() {
  const company = useCompanyDetails()
  useSeo({
    title: "Contact us",
    description: `Call RitaGreen Services on ${company.phone.display}, email ${company.email}, or send a message. ${company.officeHours}.`,
    path: "/contact",
  })

  return (
    <>
      <PageHeader eyebrow="Contact" title="Talk to the office" intro={<p>Call, email or send a message. A person in our office will pick it up.</p>} />
      <Section tone="surface">
        <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
          <div className="space-y-8">
            <div>
              <h2 className="text-[1.5rem] leading-tight">Call or email</h2>
              <dl className="mt-4 grid gap-4">
                <div>
                  <dt className="text-sm text-muted-foreground">Office</dt>
                  <dd>
                    <a href={company.phone.href} className="text-xl font-semibold text-foreground underline-offset-4 hover:underline">
                      {company.phone.display}
                    </a>
                    <span className="block text-[15px] text-muted-foreground">{company.officeHours}</span>
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-muted-foreground">{company.urgentLabel}</dt>
                  <dd>
                    <a href={company.urgentPhone.href} className="text-xl font-semibold text-foreground underline-offset-4 hover:underline">
                      {company.urgentPhone.display}
                    </a>
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-muted-foreground">Email</dt>
                  <dd>
                    <a href={`mailto:${company.email}`} className="break-all font-semibold text-foreground underline-offset-4 hover:underline">
                      {company.email}
                    </a>
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-muted-foreground">Area</dt>
                  <dd>{company.area}</dd>
                </div>
                {company.registeredAddress && (
                  <div>
                    <dt className="text-sm text-muted-foreground">Registered office</dt>
                    <dd>{company.registeredAddress}</dd>
                  </div>
                )}
              </dl>
            </div>

            <div>
              <h2 className="text-[1.5rem] leading-tight">Quicker with a focused form</h2>
              <ul className="mt-4 grid gap-2">
                <li><Link to="/request?type=staff" className="font-semibold text-primary underline underline-offset-4">Request staff</Link></li>
                <li><Link to="/request?type=cleaning" className="font-semibold text-primary underline underline-offset-4">Ask for a cleaning quote</Link></li>
                <li><Link to="/request?type=care" className="font-semibold text-primary underline underline-offset-4">Ask about support for you or a family member</Link></li>
                <li><Link to="/request?type=training" className="font-semibold text-primary underline underline-offset-4">Ask about training</Link></li>
                <li><Link to="/careers" className="font-semibold text-primary underline underline-offset-4">Apply for work</Link></li>
                <li><Link to="/data-request" className="font-semibold text-primary underline underline-offset-4">Ask what data we hold about you</Link></li>
              </ul>
            </div>
          </div>

          <div className="rounded-lg border border-border bg-background p-5 sm:p-8">
            <h2 className="text-[1.5rem] leading-tight">Send a message</h2>
            <div className="mt-6">
              <GeneralEnquiryForm />
            </div>
          </div>
        </div>
      </Section>
    </>
  )
}
