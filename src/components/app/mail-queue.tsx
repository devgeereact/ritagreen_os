import { useState } from "react"
import { RefreshCw, TriangleAlert } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "@/components/ui/table"
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { shortDate } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

/**
 * The outgoing email queue.
 *
 * queue_mail() has written to email_queue since migration 008 and nothing read it
 * back, so a message that failed five times, or that was never sent because mail
 * is switched off, left no trace anybody would see. "We emailed them" and "nobody
 * emailed them" looked identical from inside the application.
 */

type QueuedMessage = {
  id: number
  recipient: string
  subject: string
  template_key: string | null
  related_type: string | null
  related_id: number | null
  status: "queued" | "sending" | "sent" | "failed" | "cancelled"
  attempts: number
  last_error: string | null
  created_at: string
  sent_at: string | null
}

type QueueResponse = {
  messages: QueuedMessage[]
  counts: Record<string, number>
  mail_enabled: boolean
  max_attempts: number
}

const STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  sent: "default", queued: "secondary", sending: "secondary",
  failed: "destructive", cancelled: "outline",
}

const FILTERS = [
  { value: "all", label: "Everything" },
  { value: "failed", label: "Failed" },
  { value: "cancelled", label: "Not sent" },
  { value: "queued", label: "Waiting" },
  { value: "sent", label: "Sent" },
]

export function MailQueue() {
  const [filter, setFilter] = useState("all")
  const { data, loading, error, reload } = useApi<QueueResponse>(
    `comms.php?action=queue${filter === "all" ? "" : `&status=${filter}`}`, [filter],
  )
  const [busyId, setBusyId] = useState<number | null>(null)

  async function requeue(id: number) {
    setBusyId(id)
    try {
      await api(`comms.php?action=requeue&id=${id}`, { method: "POST", body: {} })
      toast.success("Back on the queue. It will go out on the next send.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not re-queue that message.")
    } finally {
      setBusyId(null)
    }
  }

  const messages = data?.messages ?? []
  const counts = data?.counts ?? {}
  const stopped = (counts.failed ?? 0) + (counts.cancelled ?? 0)

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold">Outgoing email</h2>
          <p className="text-xs text-muted-foreground">
            {stopped > 0
              ? `${stopped} message${stopped === 1 ? "" : "s"} did not go out.`
              : "Nothing is stuck."}
          </p>
        </div>
        <Select value={filter} onValueChange={setFilter}>
          <SelectTrigger className="h-8 w-40"><SelectValue /></SelectTrigger>
          <SelectContent>
            {FILTERS.map((f) => (
              <SelectItem key={f.value} value={f.value}>
                {f.label}{f.value !== "all" && counts[f.value] !== undefined ? ` (${counts[f.value]})` : ""}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/*
        Said plainly rather than left to be inferred from an empty sent list.
        With mail off, every message is recorded as "not sent" — which is not a
        delivery failure and must not be read as one.
      */}
      {data && !data.mail_enabled && (
        <p className="flex items-start gap-2 rounded-md border border-dashed px-3 py-2 text-xs">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          <span>
            Outgoing mail is switched off in the server configuration. Messages are still queued
            and kept, and are marked <span className="font-medium">not sent</span> rather than
            failed. Nothing here has reached a recipient.
          </span>
        </p>
      )}

      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}

      {data && (
        messages.length === 0 ? (
          <EmptyBlock title="Nothing here" hint="Messages appear as the system queues them." />
        ) : (
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>To</TableHead>
                  <TableHead>Subject</TableHead>
                  <TableHead>State</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {messages.map((m) => (
                  <TableRow key={m.id}>
                    <TableCell className="text-sm">
                      {m.recipient}
                      <div className="text-xs text-muted-foreground">{shortDate(m.created_at)}</div>
                    </TableCell>
                    <TableCell className="text-sm">
                      {m.subject}
                      {m.related_type && (
                        <div className="text-xs text-muted-foreground">
                          {m.related_type} #{m.related_id}
                        </div>
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[m.status]}>
                        {m.status === "cancelled" ? "not sent" : m.status}
                      </Badge>
                      {m.attempts > 0 && (
                        <span className="ml-1.5 text-xs text-muted-foreground">
                          {m.attempts}/{data.max_attempts}
                        </span>
                      )}
                      {m.last_error && (
                        <div className="mt-0.5 text-xs text-destructive">{m.last_error}</div>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      {(m.status === "failed" || m.status === "cancelled") && (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={busyId === m.id}
                          onClick={() => requeue(m.id)}
                        >
                          <RefreshCw className="mr-1 size-3.5" /> Send again
                        </Button>
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
