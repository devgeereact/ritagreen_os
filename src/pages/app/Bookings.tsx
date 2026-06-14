import { CalendarCheck, CheckCircle2, XCircle, Radio } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { ServiceRequest } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STATUS_VARIANT: Record<ServiceRequest["status"], "default" | "secondary" | "destructive" | "outline"> = {
  new: "secondary",
  quoted: "outline",
  confirmed: "default",
  declined: "destructive",
}

export default function Bookings() {
  const { data, loading, error, reload } = useApi<{ requests: ServiceRequest[] }>("bookings.php")

  async function setStatus(id: number, status: string) {
    try {
      await api(`bookings.php?action=status&id=${id}`, { method: "POST", body: { status } })
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update.")
    }
  }

  async function confirm(id: number) {
    try {
      await api(`bookings.php?action=confirm&id=${id}`, { method: "POST", body: {} })
      toast.success("Confirmed — open shift created. Broadcast it from Scheduling.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not confirm.")
    }
  }

  const requests = data?.requests ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Bookings & cover requests</h1>
        <p className="text-sm text-muted-foreground">
          Requests from the website front door. Confirm one to spin up an open shift.
        </p>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && !loading && (
        requests.length === 0 ? (
          <EmptyBlock title="No cover requests yet" hint="Requests submitted at /request appear here." />
        ) : (
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Requester</TableHead>
                  <TableHead>Service</TableHead>
                  <TableHead>Need</TableHead>
                  <TableHead>Preferred</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {requests.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell>
                      <div className="font-medium">{r.contact_name}</div>
                      <div className="text-xs text-muted-foreground">{r.client || r.email}</div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{DIVISION_LABELS[r.division] ?? r.division}</TableCell>
                    <TableCell className="max-w-xs truncate text-muted-foreground" title={r.detail}>{r.detail}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {r.preferred_start ? r.preferred_start.slice(0, 16).replace("T", " ") : shortDate(r.created_at)}
                    </TableCell>
                    <TableCell><Badge variant={STATUS_VARIANT[r.status]} className="capitalize">{r.status}</Badge></TableCell>
                    <TableCell className="text-right">
                      {r.status === "confirmed" ? (
                        <span className="inline-flex items-center gap-1 text-xs text-primary">
                          <Radio className="size-3.5" /> Shift #{r.shift_id}
                        </span>
                      ) : (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" onClick={() => confirm(r.id)}>
                            <CheckCircle2 className="mr-1 size-4" /> Confirm
                          </Button>
                          {r.status !== "quoted" && (
                            <Button size="sm" variant="outline" onClick={() => setStatus(r.id, "quoted")}>
                              <CalendarCheck className="mr-1 size-4" /> Quoted
                            </Button>
                          )}
                          {r.status !== "declined" && (
                            <Button size="sm" variant="ghost" onClick={() => setStatus(r.id, "declined")} aria-label="Decline">
                              <XCircle className="size-4 text-muted-foreground" />
                            </Button>
                          )}
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )
      )}
    </div>
  )
}
