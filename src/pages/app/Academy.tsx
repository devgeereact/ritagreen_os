import { useState } from "react"
import { Link } from "react-router-dom"
import { Plus, Sparkles, Users } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
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
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useApi } from "@/hooks/use-api"
import { api, aiDraft, ApiError } from "@/lib/api"
import type { Programme } from "@/lib/types"
import { DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const STATUS_VARIANT: Record<Programme["status"], "default" | "secondary" | "outline"> = {
  published: "default",
  draft: "secondary",
  archived: "outline",
}

export default function Academy() {
  const { data, loading, error, reload } = useApi<{ programmes: Programme[] }>("academy.php")
  const [open, setOpen] = useState(false)
  const programmes = data?.programmes ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Academy</h1>
          <p className="text-sm text-muted-foreground">
            Courses, learners and certificates. Completed learners get a verifiable certificate.
          </p>
        </div>
        <NewProgrammeDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>

      {loading && <LoadingBlock rows={5} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && programmes.length === 0 && (
        <EmptyBlock
          title="No programmes yet"
          hint="Create your first course. You can draft the description with AI."
          action={<Button onClick={() => setOpen(true)}><Plus className="mr-1 size-4" /> New programme</Button>}
        />
      )}

      {data && !loading && programmes.length > 0 && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Programme</TableHead>
                <TableHead>Division</TableHead>
                <TableHead>Learners</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {programmes.map((p) => (
                <TableRow key={p.id}>
                  <TableCell>
                    <Link to={`/app/academy/${p.id}`} className="font-medium text-primary hover:underline">
                      {p.title}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{DIVISION_LABELS[p.division] ?? p.division}</TableCell>
                  <TableCell>
                    <span className="inline-flex items-center gap-1"><Users className="size-3.5 text-muted-foreground" /> {p.enrolments}</span>
                  </TableCell>
                  <TableCell><Badge variant={STATUS_VARIANT[p.status]} className="capitalize">{p.status}</Badge></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
    </div>
  )
}

function NewProgrammeDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const [title, setTitle] = useState("")
  const [description, setDescription] = useState("")
  const [division, setDivision] = useState("academy")
  const [submitting, setSubmitting] = useState(false)
  const [drafting, setDrafting] = useState(false)

  async function draft() {
    if (!title.trim()) {
      toast.error("Add a title first so the AI has something to work from.")
      return
    }
    setDrafting(true)
    try {
      const text = await aiDraft(
        `Write a clear, professional course description (2 short paragraphs) plus 4 bullet learning outcomes for a UK Health & Social Care training course titled "${title.trim()}". UK English, no marketing fluff.`,
      )
      setDescription(text)
      toast.success("Draft ready. Edit as needed.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "AI drafting failed.")
    } finally {
      setDrafting(false)
    }
  }

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!title.trim()) {
      toast.error("Add a programme title.")
      return
    }
    setSubmitting(true)
    try {
      await api("academy.php", {
        method: "POST",
        body: { title: title.trim(), description: description.trim(), division },
      })
      toast.success("Programme created.")
      onOpenChange(false)
      setTitle("")
      setDescription("")
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the programme.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> New programme</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New programme</DialogTitle>
          <DialogDescription>Create a course. Draft the description with AI if you like.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title</Label>
            <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Health & Social Care (Level 3)" required />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="division">Division</Label>
            <Select value={division} onValueChange={setDivision}>
              <SelectTrigger id="division"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="academy">Academy</SelectItem>
                <SelectItem value="care">Care</SelectItem>
                <SelectItem value="cleaning">Cleaning</SelectItem>
                <SelectItem value="security">Security</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="description">Description</Label>
              <Button type="button" size="sm" variant="outline" onClick={draft} disabled={drafting}>
                <Sparkles className="mr-1 size-3.5" /> {drafting ? "Drafting…" : "Draft with AI"}
              </Button>
            </div>
            <Textarea id="description" value={description} onChange={(e) => setDescription(e.target.value)} rows={6} placeholder="What the course covers and who it's for…" />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Create programme"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
