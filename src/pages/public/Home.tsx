import { Link } from "react-router-dom"
import { ArrowRight, BadgeCheck, ShieldCheck, Clock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Section, CtaBand } from "@/components/public/section"
import { DIVISIONS } from "@/lib/divisions"

export default function Home() {
  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-border bg-secondary/30">
        <div className="mx-auto grid max-w-6xl gap-10 px-4 py-16 md:grid-cols-2 md:py-24">
          <div className="flex flex-col justify-center">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-primary">
              RitaGreen Healthcare Services
            </p>
            <h1 className="mt-3 text-4xl font-bold leading-tight tracking-tight md:text-5xl">
              Trained, checked and trusted staff, from{" "}
              <span className="text-primary">one name</span>.
            </h1>
            <p className="mt-5 max-w-lg text-lg text-muted-foreground">
              We staff and run care, train the next wave of carers, keep buildings clean and safe,
              and guard the places people live and work. Four divisions, one trusted partner.
            </p>
            <div className="mt-7 flex flex-wrap gap-3">
              <Button asChild size="lg">
                <Link to="/contact">
                  Enquire now <ArrowRight className="ml-1 size-4" />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <Link to="/services">Our services</Link>
              </Button>
            </div>
            <div className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
              <span className="flex items-center gap-2"><BadgeCheck className="size-4 text-primary" /> DBS-checked staff</span>
              <span className="flex items-center gap-2"><ShieldCheck className="size-4 text-primary" /> SIA-licensed guards</span>
              <span className="flex items-center gap-2"><Clock className="size-4 text-primary" /> Fast shift cover</span>
            </div>
          </div>

          <div className="relative hidden md:block">
            <div className="grid h-full grid-cols-2 gap-4">
              {DIVISIONS.map((d) => (
                <Link
                  key={d.id}
                  to={`/services#${d.id}`}
                  className="group flex flex-col justify-between rounded-2xl border border-border bg-card p-5 shadow-sm transition-shadow hover:shadow-md"
                >
                  <span
                    className="grid size-10 place-items-center rounded-lg text-sm font-bold text-white"
                    style={{ backgroundColor: d.accent }}
                  >
                    {d.name.charAt(0)}
                  </span>
                  <div>
                    <p className="mt-4 text-sm font-semibold">{d.name}</p>
                    <p className="mt-1 text-xs text-muted-foreground">{d.tagline}</p>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* What we do */}
      <Section>
        <div className="max-w-2xl">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">
            One purpose, four divisions
          </h2>
          <p className="mt-3 text-muted-foreground">
            RitaGreen looks after people through every part of their day. Each division stands on its
            own, and together they let one client call a single trusted name for staff, training,
            cleaning or security.
          </p>
        </div>
        <div className="mt-10 grid gap-5 md:grid-cols-2">
          {DIVISIONS.map((d) => (
            <div key={d.id} className="rounded-xl border border-border bg-card p-6">
              <div className="flex items-center gap-2">
                <span className="size-3 rounded-full" style={{ backgroundColor: d.accent }} />
                <h3 className="font-semibold">{d.name}</h3>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">{d.blurb}</p>
              <Link
                to={`/services#${d.id}`}
                className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
              >
                Learn more <ArrowRight className="size-3.5" />
              </Link>
            </div>
          ))}
        </div>
      </Section>

      {/* Proof */}
      <section className="border-y border-border bg-secondary/40">
        <div className="mx-auto grid max-w-6xl gap-6 px-4 py-12 sm:grid-cols-3">
          {[
            { n: "4", l: "Divisions under one roof" },
            { n: "100%", l: "Staff DBS-checked before first shift" },
            { n: "London", l: "Wide coverage across the capital" },
          ].map((s) => (
            <div key={s.l} className="text-center">
              <div className="text-3xl font-bold text-primary">{s.n}</div>
              <div className="mt-1 text-sm text-muted-foreground">{s.l}</div>
            </div>
          ))}
        </div>
      </section>

      <CtaBand />
    </>
  )
}
