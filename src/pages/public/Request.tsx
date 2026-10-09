import { useSearchParams } from "react-router-dom"
import { PageHeader, Section } from "@/components/public/section"
import { EnquiryForm } from "@/components/public/forms/EnquiryForm"
import { useCompanyDetails } from "@/lib/company"
import type { EnquiryKind } from "@/lib/service-pages"
import type { ServiceId } from "@/lib/services"
import { useSeo } from "@/hooks/use-seo"
import { cn } from "@/lib/utils"

// /request, the site's main call to action. One page, four focused forms, each
// going where the 2026 plan sends it (file 05): staff to bookings.php, a
// cleaning quote to quotes.php, care and training to contact.php. ?type= picks
// the form, so /quote can send people straight to the cleaning quote and a
// service page's "Request staff" can arrive with ?service= set.

const KINDS: { kind: EnquiryKind; label: string; help: string }[] = [
  { kind: "staff", label: "Staff for a shift or a rota", help: "Healthcare, cleaning or security staff for your site." },
  { kind: "cleaning", label: "A cleaning quote", help: "Regular or one-off cleaning, priced in writing." },
  { kind: "care", label: "Support for me or a family member", help: "Supported living or support at home. We call you." },
  { kind: "training", label: "Training", help: "Care Certificate, mandatory or refresher training." },
]

const STAFF_IDS: ServiceId[] = ["care", "cleaning", "security"]

export default function Request() {
  const company = useCompanyDetails()
  const [params, setParams] = useSearchParams()
  const kind: EnquiryKind = (KINDS.find((k) => k.kind === params.get("type"))?.kind ?? "staff")
  const serviceParam = params.get("service") as ServiceId | null
  const service = serviceParam && STAFF_IDS.includes(serviceParam) ? serviceParam : undefined

  useSeo({
    title: "Request staff, a quote or a call back",
    description:
      "Request healthcare, cleaning or security staff, ask for a cleaning quote, or ask us to call about support or training. RitaGreen Services, London.",
    path: "/request",
  })

  function choose(next: EnquiryKind) {
    const p = new URLSearchParams(params)
    p.set("type", next)
    if (next !== "staff") p.delete("service")
    setParams(p, { replace: true })
  }

  return (
    <>
      <PageHeader
        eyebrow="Request"
        breadcrumb="Request staff"
        title="What do you need?"
        intro={
          <p>
            Choose one and fill in the short form. If it is urgent, call{" "}
            <a href={company.phone.href} className="font-semibold text-foreground underline underline-offset-4">
              {company.phone.display}
            </a>{" "}
            or the {company.urgentLabel.toLowerCase()} on{" "}
            <a href={company.urgentPhone.href} className="font-semibold text-foreground underline underline-offset-4">
              {company.urgentPhone.display}
            </a>
            .
          </p>
        }
      />
      <Section tone="surface">
        <div className="grid gap-10 lg:grid-cols-[0.75fr_1.25fr]">
          <fieldset>
            <legend className="text-[15px] font-semibold">I need</legend>
            <div className="mt-3 grid gap-3">
              {KINDS.map((k) => (
                <label
                  key={k.kind}
                  className={cn(
                    "flex cursor-pointer items-start gap-3 rounded-lg border bg-background p-4 transition-colors",
                    k.kind === kind ? "border-primary bg-accent" : "border-border hover:border-primary",
                  )}
                >
                  <input
                    type="radio"
                    name="request-type"
                    value={k.kind}
                    checked={k.kind === kind}
                    onChange={() => choose(k.kind)}
                    className="mt-1 size-4 shrink-0 accent-[#33512D]"
                  />
                  <span>
                    <span className="block font-semibold">{k.label}</span>
                    <span className="block text-[15px] text-muted-foreground">{k.help}</span>
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
          <div className="rounded-lg border border-border bg-background p-5 sm:p-8">
            <h2 className="text-[1.5rem] leading-tight">{KINDS.find((k) => k.kind === kind)?.label}</h2>
            <div className="mt-6">
              {/* Keyed so switching type starts that form fresh. */}
              <EnquiryForm key={`${kind}-${service ?? ""}`} kind={kind} service={service} />
            </div>
          </div>
        </div>
      </Section>
    </>
  )
}
