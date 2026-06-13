import { Link, useParams } from "react-router-dom"
import { ArrowLeft, Building2, Mail, Phone, Briefcase, ReceiptText, FileText, Inbox } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { useApi } from "@/hooks/use-api"
import type { Client, Job, Quote, Invoice, Enquiry } from "@/lib/types"
import { shortDate, gbp, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Site = { id: number; name: string; address: string | null }

type Event = {
  date: string
  kind: "job" | "quote" | "invoice" | "enquiry"
  title: string
  sub: string
  amount?: number
  status?: string
}

const EVENT_ICON = {
  job: Briefcase,
  quote: FileText,
  invoice: ReceiptText,
  enquiry: Inbox,
} as const

// Activity timeline assembled from the module endpoints filtered to this client.
function ClientTimeline({ clientId, email }: { clientId: number; email: string | null }) {
  const jobs = useApi<{ jobs: Job[] }>("jobs.php")
  const quotes = useApi<{ quotes: Quote[] }>("quotes.php")
  const invoices = useApi<{ invoices: Invoice[] }>("invoices.php")
  const enquiries = useApi<{ enquiries: Enquiry[] }>("enquiries.php")

  const events: Event[] = [
    ...(jobs.data?.jobs ?? [])
      .filter((j) => j.client_id === clientId)
      .map((j) => ({ date: j.scheduled_for || j.created_at, kind: "job" as const, title: j.title, sub: DIVISION_LABELS[j.division] ?? j.division, status: j.status })),
    ...(quotes.data?.quotes ?? [])
      .filter((q) => q.client_id === clientId)
      .map((q) => ({ date: q.created_at, kind: "quote" as const, title: `${q.number} — ${q.title}`, sub: DIVISION_LABELS[q.division] ?? q.division, amount: q.total_pence, status: q.status })),
    ...(invoices.data?.invoices ?? [])
      .filter((i) => i.client_id === clientId)
      .map((i) => ({ date: i.created_at, kind: "invoice" as const, title: i.number, sub: DIVISION_LABELS[i.division] ?? i.division, amount: i.total_pence, status: i.status })),
    ...(enquiries.data?.enquiries ?? [])
      .filter((e) => email != null && e.email.toLowerCase() === email.toLowerCase())
      .map((e) => ({ date: e.created_at, kind: "enquiry" as const, title: "Website enquiry", sub: DIVISION_LABELS[e.division] ?? e.division, status: e.status })),
  ].sort((a, b) => (a.date < b.date ? 1 : -1))

  return (
    <Card>
      <CardHeader><CardTitle className="text-base">Activity</CardTitle></CardHeader>
      <CardContent>
        {events.length === 0 ? (
          <EmptyBlock title="No activity yet" hint="Jobs, quotes, invoices and enquiries for this client will appear here." />
        ) : (
          <ul className="space-y-4">
            {events.map((e, i) => {
              const Icon = EVENT_ICON[e.kind]
              return (
                <li key={i} className="flex gap-3">
                  <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <p className="truncate font-medium">{e.title}</p>
                      <span className="shrink-0 text-xs text-muted-foreground">{shortDate(e.date)}</span>
                    </div>
                    <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <span className="capitalize">{e.kind}</span> · <span>{e.sub}</span>
                      {e.amount != null && <span className="font-medium text-foreground">{gbp(e.amount)}</span>}
                      {e.status && <Badge variant="secondary" className="capitalize">{e.status}</Badge>}
                    </div>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

export default function ClientDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useApi<{ client: Client; sites: Site[] }>(
    id ? `clients.php?id=${id}` : null,
    [id],
  )

  return (
    <div className="space-y-6">
      <Link to="/app/clients" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> All clients
      </Link>

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        <>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold tracking-tight">{data.client.name}</h1>
              <p className="text-sm capitalize text-muted-foreground">
                {data.client.type.replace("_", " ")}
              </p>
            </div>
            <Badge className="capitalize">{data.client.status}</Badge>
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            <Card className="md:col-span-1">
              <CardHeader><CardTitle className="text-base">Contact</CardTitle></CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p className="text-muted-foreground">{data.client.contact_name || "No named contact"}</p>
                {data.client.email && (
                  <p className="flex items-center gap-2"><Mail className="size-4 text-primary" /> {data.client.email}</p>
                )}
                {data.client.phone && (
                  <p className="flex items-center gap-2"><Phone className="size-4 text-primary" /> {data.client.phone}</p>
                )}
                <p className="pt-2 text-xs text-muted-foreground">Client since {shortDate(data.client.created_at)}</p>
              </CardContent>
            </Card>

            <Card className="md:col-span-1">
              <CardHeader><CardTitle className="text-base">Divisions used</CardTitle></CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {data.client.divisions
                  ? data.client.divisions.split(",").filter(Boolean).map((d) => (
                      <Badge key={d} variant="secondary">{DIVISION_LABELS[d] ?? d}</Badge>
                    ))
                  : <span className="text-sm text-muted-foreground">None assigned yet.</span>}
              </CardContent>
            </Card>

            <Card className="md:col-span-1">
              <CardHeader><CardTitle className="text-base">Sites</CardTitle></CardHeader>
              <CardContent>
                {data.sites.length === 0 ? (
                  <EmptyBlock title="No sites yet" hint="Add the buildings or homes this client covers." />
                ) : (
                  <ul className="space-y-2 text-sm">
                    {data.sites.map((s) => (
                      <li key={s.id} className="flex items-start gap-2">
                        <Building2 className="mt-0.5 size-4 text-primary" />
                        <div>
                          <p className="font-medium">{s.name}</p>
                          {s.address && <p className="text-muted-foreground">{s.address}</p>}
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          <ClientTimeline clientId={data.client.id} email={data.client.email} />
        </>
      )}
    </div>
  )
}
