import { useEffect, useState } from "react"
import { Sparkles, Copy, Check, Inbox } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { Textarea } from "@/components/ui/textarea"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useApi } from "@/hooks/use-api"
import { api, aiDraft, ApiError } from "@/lib/api"
import type { Enquiry } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

export default function Enquiries() {
  const { data, loading, error, reload } = useApi<{ enquiries: Enquiry[] }>("enquiries.php")
  const [active, setActive] = useState<Enquiry | null>(null)

  async function markHandled(id: number) {
    try {
      await api(`enquiries.php?action=handled&id=${id}`, { method: "POST", body: {} })
      toast.success("Marked handled.")
      setActive(null)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update the enquiry.")
    }
  }

  const enquiries = data?.enquiries ?? []
  const newCount = enquiries.filter((e) => e.status === "new").length

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Enquiries</h1>
        <p className="text-sm text-muted-foreground">
          Everything from the website contact form. {newCount > 0 ? `${newCount} new.` : "All caught up."}
        </p>
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && enquiries.length === 0 && (
        <EmptyBlock title="No enquiries yet" hint="Submissions from the public contact form land here." />
      )}

      {data && !loading && enquiries.length > 0 && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>From</TableHead>
                <TableHead>About</TableHead>
                <TableHead>Received</TableHead>
                <TableHead>Status</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {enquiries.map((e) => (
                <TableRow key={e.id}>
                  <TableCell>
                    <div className="font-medium">{e.name}</div>
                    <div className="text-xs text-muted-foreground">{e.email}</div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {DIVISION_LABELS[e.division] ?? "General"}
                  </TableCell>
                  <TableCell className="text-muted-foreground">{shortDate(e.created_at)}</TableCell>
                  <TableCell>
                    <Badge variant={e.status === "new" ? "secondary" : "outline"} className="capitalize">
                      {e.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <Button size="sm" variant="outline" onClick={() => setActive(e)}>Open</Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <EnquiryDialog enquiry={active} onClose={() => setActive(null)} onHandled={markHandled} />
    </div>
  )
}

function EnquiryDialog({
  enquiry,
  onClose,
  onHandled,
}: {
  enquiry: Enquiry | null
  onClose: () => void
  onHandled: (id: number) => void
}) {
  const [draft, setDraft] = useState("")
  const [drafting, setDrafting] = useState(false)
  const [routing, setRouting] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  // Reset local state whenever a different enquiry opens.
  const key = enquiry?.id ?? 0
  useEffect(() => {
    setDraft("")
    setRouting(null)
    setCopied(false)
  }, [key])

  async function draftReply() {
    if (!enquiry) return
    setDrafting(true)
    try {
      const text = await aiDraft(
        `A prospective client sent this enquiry to RitaGreen (care, training, cleaning, security). ` +
          `Write a warm, professional reply (UK English, no fluff) that acknowledges their need, asks one or two ` +
          `clarifying questions, and offers next steps. Sign off as "The RitaGreen team".\n\n` +
          `From: ${enquiry.name}\nAbout: ${DIVISION_LABELS[enquiry.division] ?? "General"}\n` +
          `Message: ${enquiry.message}`,
      )
      setDraft(text)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "AI drafting failed.")
    } finally {
      setDrafting(false)
    }
  }

  async function suggestRoute() {
    if (!enquiry) return
    try {
      const text = await aiDraft(
        `Classify this enquiry into exactly one RitaGreen division: Healthcare & Care, Academy & Training, ` +
          `Cleaning Services, or Security Services. Reply with only the division name and a 6-word reason.\n\n` +
          `Message: ${enquiry.message}`,
      )
      setRouting(text)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "AI routing failed.")
    }
  }

  function copy() {
    navigator.clipboard.writeText(draft)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  return (
    <Dialog open={!!enquiry} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-lg" key={key}>
        {enquiry && (
          <>
            <DialogHeader>
              <DialogTitle>{enquiry.name}</DialogTitle>
              <DialogDescription>
                {enquiry.email}
                {enquiry.phone ? ` · ${enquiry.phone}` : ""} · {DIVISION_LABELS[enquiry.division] ?? "General"}
              </DialogDescription>
            </DialogHeader>

            <div className="rounded-md bg-muted p-3 text-sm">{enquiry.message}</div>

            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" onClick={draftReply} disabled={drafting}>
                <Sparkles className="mr-1 size-3.5" /> {drafting ? "Drafting…" : "AI draft reply"}
              </Button>
              <Button size="sm" variant="outline" onClick={suggestRoute}>
                <Sparkles className="mr-1 size-3.5" /> AI suggest routing
              </Button>
            </div>

            {routing && <p className="rounded-md bg-accent p-2 text-xs text-accent-foreground">{routing}</p>}

            {draft && (
              <div className="space-y-2">
                <Textarea value={draft} onChange={(e) => setDraft(e.target.value)} rows={8} />
                <div className="flex justify-between">
                  <Button size="sm" variant="ghost" onClick={copy}>
                    {copied ? <Check className="mr-1 size-3.5" /> : <Copy className="mr-1 size-3.5" />}
                    {copied ? "Copied" : "Copy"}
                  </Button>
                  <a
                    href={`mailto:${enquiry.email}?subject=Your%20RitaGreen%20enquiry&body=${encodeURIComponent(draft)}`}
                    className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                  >
                    <Inbox className="size-3.5" /> Open in email
                  </a>
                </div>
              </div>
            )}

            <div className="flex justify-end border-t border-border pt-3">
              {enquiry.status !== "handled" && (
                <Button onClick={() => onHandled(enquiry.id)}>Mark handled</Button>
              )}
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
