import { PageHeader, Section, CtaBand } from "@/components/public/section"
import { DIVISIONS } from "@/lib/divisions"

export default function About() {
  return (
    <>
      <PageHeader
        eyebrow="About RitaGreen"
        title="Looking after people through every part of their day"
        intro="RitaGreen Healthcare Services Ltd has grown into four businesses that share one purpose: bringing trained, checked and trusted staff to the people, buildings and events that need them."
      />

      <Section>
        <div className="grid gap-12 md:grid-cols-3">
          <div className="md:col-span-2 space-y-4 text-muted-foreground">
            <p>
              We started in care, and we still run care every day. Over time we saw the same need
              across our clients: not just carers, but the training that makes good carers, the
              cleaning that keeps care settings safe, and the security that protects the places people
              live and work.
            </p>
            <p>
              So we built four divisions around one promise. Whoever turns up in a RitaGreen uniform
              is qualified for the job, checked before their first shift, and backed by an office that
              knows where they are and what they are doing.
            </p>
            <p>
              We work with care homes, the NHS, landlords, managing agents and event organisers
              across London. For each of them, the value is the same: one trusted name to call,
              instead of juggling four suppliers.
            </p>
          </div>
          <div className="space-y-6">
            <div className="rounded-xl border border-border bg-card p-5">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-primary">Our mission</h2>
              <p className="mt-2 text-sm text-muted-foreground">
                To keep people safe, cared for and supported through life by bringing trained, checked
                and trusted staff to the doorsteps, buildings and events that need them.
              </p>
            </div>
            <div className="rounded-xl border border-border bg-card p-5">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-primary">Our vision</h2>
              <p className="mt-2 text-sm text-muted-foreground">
                A London where a care home, a landlord or an event organiser can call one trusted name
                for staff, training, cleaning or security, and know every worker is qualified, checked
                and ready.
              </p>
            </div>
          </div>
        </div>

        <div className="mt-14">
          <h2 className="text-2xl font-bold tracking-tight">The four divisions</h2>
          <div className="mt-6 grid gap-4 sm:grid-cols-2">
            {DIVISIONS.map((d) => (
              <div key={d.id} className="rounded-xl border border-border bg-card p-5">
                <div className="flex items-center gap-2">
                  <span className="size-3 rounded-full" style={{ backgroundColor: d.accent }} />
                  <h3 className="font-semibold">{d.name}</h3>
                </div>
                <p className="mt-2 text-sm text-muted-foreground">{d.blurb}</p>
              </div>
            ))}
          </div>
        </div>
      </Section>

      <CtaBand title="Work with RitaGreen" body="Whether you need staff, training, cleaning or security, start with one conversation." />
    </>
  )
}
