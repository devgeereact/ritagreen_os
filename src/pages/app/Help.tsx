import { Phone, Mail, Clock, AlertTriangle, BookOpen, LifeBuoy } from "lucide-react"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Link } from "react-router-dom"
import { COMPANY } from "@/lib/company"

// Support hub — how to get help, who to call, and the urgent cover line.
export default function Help() {
  const channels = [
    { icon: Phone, label: "Office line", value: COMPANY.phone, href: COMPANY.phoneHref, hint: COMPANY.hours },
    { icon: Mail, label: "Email", value: COMPANY.email, href: `mailto:${COMPANY.email}`, hint: "We reply within one working day" },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Help &amp; support</h1>
        <p className="mt-1 text-sm text-muted-foreground">Get in touch, or find your way around RitaGreen.</p>
      </div>

      {/* Urgent cover — always first */}
      <Card className="flex items-start gap-4 border-warning/40 bg-warning/5 p-5">
        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-warning/15 text-warning">
          <AlertTriangle className="size-5" />
        </span>
        <div className="flex-1">
          <p className="font-semibold">Urgent cover needed?</p>
          <p className="mt-0.5 text-sm text-muted-foreground">
            The 24-hour line is staffed for last-minute shift cover and emergencies.
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <a href={COMPANY.emergencyPhoneHref}>{COMPANY.emergencyPhone}</a>
        </Button>
      </Card>

      {/* Contact channels */}
      <div className="grid gap-4 sm:grid-cols-2">
        {channels.map((c) => (
          <Card key={c.label} className="p-5">
            <span className="grid size-10 place-items-center rounded-lg bg-muted text-muted-foreground">
              <c.icon className="size-5" />
            </span>
            <p className="mt-3 text-sm text-muted-foreground">{c.label}</p>
            <a href={c.href} className="mt-0.5 block font-semibold text-primary hover:underline">{c.value}</a>
            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
              <Clock className="size-3" /> {c.hint}
            </p>
          </Card>
        ))}
      </div>

      {/* Quick links */}
      <Card className="p-5">
        <p className="flex items-center gap-2 font-semibold"><BookOpen className="size-4 text-primary" /> Quick links</p>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          <Link to="/app/settings" className="rounded-lg border border-border px-4 py-3 text-sm font-medium transition-colors hover:border-primary/40 hover:bg-muted/50">Account &amp; security settings</Link>
          <Link to="/app" className="rounded-lg border border-border px-4 py-3 text-sm font-medium transition-colors hover:border-primary/40 hover:bg-muted/50">Back to Command Centre</Link>
          <a href="/" className="rounded-lg border border-border px-4 py-3 text-sm font-medium transition-colors hover:border-primary/40 hover:bg-muted/50">View public website</a>
          <a href={`mailto:${COMPANY.email}?subject=RitaGreen%20support`} className="rounded-lg border border-border px-4 py-3 text-sm font-medium transition-colors hover:border-primary/40 hover:bg-muted/50">Report a problem</a>
        </div>
      </Card>

      <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
        <LifeBuoy className="size-3" /> {COMPANY.legalName} · Company no. {COMPANY.companyNo}
      </p>
    </div>
  )
}
