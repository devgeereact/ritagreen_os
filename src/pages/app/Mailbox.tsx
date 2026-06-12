import { useState } from "react"
import { Mail, RefreshCw, Send, CornerUpLeft, CircleDot } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Textarea } from "@/components/ui/textarea"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"
import { cn } from "@/lib/utils"

type MailListItem = {
  uid: number
  subject: string
  from: string
  from_email: string
  date: string | null
  seen: boolean
  answered: boolean
}

type MailMessage = {
  uid: number
  subject: string
  from: string
  from_email: string
  message_id: string
  date: string | null
  body: string
}

function when(iso: string | null): string {
  if (!iso) return ""
  const d = new Date(iso)
  return d.toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })
}

// Strip the display name down to something compact for the list.
function senderName(from: string, email: string): string {
  const name = from.replace(/<[^>]*>/, "").replace(/"/g, "").trim()
  return name || email || "Unknown sender"
}

export default function Mailbox() {
  const { data, loading, error, reload } = useApi<{ messages: MailListItem[] }>("inbox.php?action=list&limit=40")
  const [activeUid, setActiveUid] = useState<number | null>(null)
  const [message, setMessage] = useState<MailMessage | null>(null)
  const [loadingMsg, setLoadingMsg] = useState(false)
  const [reply, setReply] = useState("")
  const [sending, setSending] = useState(false)

  const messages = data?.messages ?? []
  const unread = messages.filter((m) => !m.seen).length

  async function open(uid: number) {
    setActiveUid(uid)
    setMessage(null)
    setReply("")
    setLoadingMsg(true)
    try {
      const res = await api<{ message: MailMessage }>(`inbox.php?action=message&uid=${uid}`)
      setMessageSeen(uid)
      setMessage(res.message)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not open the message.")
    } finally {
      setLoadingMsg(false)
    }
  }

  // Optimistically flip the list row to read once opened.
  function setMessageSeen(uid: number) {
    const row = messages.find((m) => m.uid === uid)
    if (row) row.seen = true
  }

  async function sendReply() {
    if (!message || !reply.trim()) return
    setSending(true)
    try {
      await api("inbox.php?action=reply", {
        method: "POST",
        body: {
          to: message.from_email,
          subject: message.subject,
          message: reply.trim(),
          in_reply_to: message.message_id,
          uid: message.uid,
        },
      })
      toast.success(`Reply sent to ${message.from_email}.`)
      setReply("")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "The reply could not be sent.")
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Inbox</h1>
          <p className="text-sm text-muted-foreground">
            Mail to hello@ritagreenservices.com.{" "}
            {unread > 0 ? `${unread} unread.` : "All caught up."}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={reload} disabled={loading}>
          <RefreshCw className={cn("mr-1 size-4", loading && "animate-spin")} /> Refresh
        </Button>
      </div>

      {loading && <LoadingBlock rows={6} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && messages.length === 0 && (
        <EmptyBlock title="No mail yet" hint="New email to the mailbox will appear here." />
      )}

      {data && !loading && messages.length > 0 && (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,22rem)_1fr]">
          {/* Message list */}
          <Card className="divide-y divide-border/60 overflow-hidden p-0">
            <div className="max-h-[70vh] divide-y divide-border/60 overflow-y-auto">
              {messages.map((m) => {
                const active = m.uid === activeUid
                return (
                  <button
                    key={m.uid}
                    onClick={() => open(m.uid)}
                    className={cn(
                      "flex w-full flex-col gap-1 px-4 py-3 text-left transition-colors",
                      active ? "bg-primary/10" : "hover:bg-muted/50",
                    )}
                  >
                    <div className="flex items-center gap-2">
                      {!m.seen && <CircleDot className="size-3 shrink-0 text-primary" />}
                      <span className={cn("flex-1 truncate text-sm", m.seen ? "font-medium" : "font-bold")}>
                        {senderName(m.from, m.from_email)}
                      </span>
                      <span className="shrink-0 text-xs text-muted-foreground">{when(m.date)}</span>
                    </div>
                    <span className={cn("truncate text-sm", m.seen ? "text-muted-foreground" : "text-foreground")}>
                      {m.subject}
                    </span>
                    {m.answered && (
                      <span className="inline-flex items-center gap-1 text-xs text-primary">
                        <CornerUpLeft className="size-3" /> Replied
                      </span>
                    )}
                  </button>
                )
              })}
            </div>
          </Card>

          {/* Reading pane */}
          <Card className="flex min-h-[70vh] flex-col p-0">
            {!activeUid && (
              <div className="flex flex-1 flex-col items-center justify-center p-10 text-center">
                <span className="grid size-12 place-items-center rounded-2xl bg-primary/10 text-primary">
                  <Mail className="size-6" />
                </span>
                <p className="mt-4 font-semibold">Select a message</p>
                <p className="mt-1 text-sm text-muted-foreground">Pick an email on the left to read and reply.</p>
              </div>
            )}

            {activeUid && loadingMsg && (
              <div className="p-6">
                <LoadingBlock rows={5} />
              </div>
            )}

            {activeUid && !loadingMsg && message && (
              <>
                <div className="border-b border-border/60 p-6">
                  <h2 className="text-lg font-bold">{message.subject}</h2>
                  <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">{senderName(message.from, message.from_email)}</span>
                    <span>&lt;{message.from_email}&gt;</span>
                    <span className="ml-auto">{when(message.date)}</span>
                  </div>
                </div>

                <div className="flex-1 overflow-y-auto whitespace-pre-wrap p-6 text-sm leading-relaxed text-foreground">
                  {message.body || <span className="text-muted-foreground">(This message has no plain-text body.)</span>}
                </div>

                <div className="border-t border-border/60 bg-muted/30 p-4">
                  <Textarea
                    value={reply}
                    onChange={(e) => setReply(e.target.value)}
                    rows={4}
                    placeholder={`Reply to ${message.from_email}…`}
                    className="bg-card"
                  />
                  <div className="mt-3 flex items-center justify-between">
                    <p className="text-xs text-muted-foreground">Sends from hello@ritagreenservices.com</p>
                    <Button onClick={sendReply} disabled={sending || !reply.trim()}>
                      <Send className="mr-1 size-4" /> {sending ? "Sending…" : "Send reply"}
                    </Button>
                  </div>
                </div>
              </>
            )}
          </Card>
        </div>
      )}
    </div>
  )
}
