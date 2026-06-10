import { Link, useParams } from "react-router-dom"
import { ArrowLeft, Building2, Mail, Phone } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { useApi } from "@/hooks/use-api"
import type { Client } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

type Site = { id: number; name: string; address: string | null }

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
        </>
      )}
    </div>
  )
}
