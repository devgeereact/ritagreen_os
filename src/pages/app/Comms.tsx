import { useState } from "react"
import { Megaphone, Sparkles, Send } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import { api, aiDraft, ApiError } from "@/lib/api"
import type { Announcement } from "@/lib/types"
import { shortDate, DIVISION_LABELS } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const AUDIENCES = [
  { value: "all", label: "Whole group" },
  { value: "care", label: "Care" },
  { value: "academy", label: "Academy" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
]

export default function Comms() {
  const { hasRole } = useAuth()
  const canPost = hasRole("super_admin", "admin", "manager")
  const { data, loading, error, reload } = useApi<{ announcements: Announcement[] }>("comms.php")

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Communication</h1>
        <p className="text-sm text-muted-foreground">Broadcasts to the whole group or one division.</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {canPost && (
          <div className="lg:col-span-1">
            <Compose onPosted={reload} />
          </div>
        )}

        <div className={canPost ? "lg:col-span-2" : "lg:col-span-3"}>
          {loading && <LoadingBlock rows={4} />}
          {error && <ErrorBlock message={error} onRetry={reload} />}
          {data && !loading && data.announcements.length === 0 && (
            <EmptyBlock title="No announcements yet" hint="Broadcasts to your team appear here." />
          )}
          {data && !loading && data.announcements.length > 0 && (
            <div className="space-y-4">
              {data.announcements.map((a) => (
                <Card key={a.id}>
                  <CardHeader className="pb-2">
                    <div className="flex items-center justify-between gap-2">
                      <CardTitle className="text-base">{a.title}</CardTitle>
                      <Badge variant={a.audience === "all" ? "default" : "secondary"}>
                        {a.audience === "all" ? "Whole group" : DIVISION_LABELS[a.audience] ?? a.audience}
                      </Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {a.author || "RitaGreen"} · {shortDate(a.created_at)}
                    </p>
                  </CardHeader>
                  <CardContent className="whitespace-pre-wrap text-sm text-muted-foreground">{a.body}</CardContent>
                </Card>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function Compose({ onPosted }: { onPosted: () => void }) {
  const [audience, setAudience] = useState("all")
  const [title, setTitle] = useState("")
  const [bodyText, setBodyText] = useState("")
  const [submitting, setSubmitting] = useState(false)
  const [drafting, setDrafting] = useState(false)

  async function draft() {
    if (!title.trim()) {
      toast.error("Add a subject so the AI has a topic.")
      return
    }
    setDrafting(true)
    try {
      const text = await aiDraft(
        `Write a short, clear internal staff announcement (UK English, friendly but professional, no fluff) ` +
          `for RitaGreen about: "${title.trim()}". 2-4 sentences.`,
      )
      setBodyText(text)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "AI drafting failed.")
    } finally {
      setDrafting(false)
    }
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!title.trim() || !bodyText.trim()) {
      toast.error("Add a subject and a message.")
      return
    }
    setSubmitting(true)
    try {
      await api("comms.php", { method: "POST", body: { audience, title: title.trim(), body: bodyText.trim() } })
      toast.success("Announcement posted.")
      setTitle("")
      setBodyText("")
      onPosted()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not post.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Megaphone className="size-4 text-primary" /> New announcement
        </CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="audience">Audience</Label>
            <Select value={audience} onValueChange={setAudience}>
              <SelectTrigger id="audience"><SelectValue /></SelectTrigger>
              <SelectContent>
                {AUDIENCES.map((a) => <SelectItem key={a.value} value={a.value}>{a.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="title">Subject</Label>
            <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} required />
          </div>
          <div className="grid gap-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="body">Message</Label>
              <Button type="button" size="sm" variant="outline" onClick={draft} disabled={drafting}>
                <Sparkles className="mr-1 size-3.5" /> {drafting ? "Drafting…" : "Draft with AI"}
              </Button>
            </div>
            <Textarea id="body" value={bodyText} onChange={(e) => setBodyText(e.target.value)} rows={6} required />
          </div>
          <Button type="submit" disabled={submitting}>
            <Send className="mr-1 size-4" /> {submitting ? "Posting…" : "Post announcement"}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
