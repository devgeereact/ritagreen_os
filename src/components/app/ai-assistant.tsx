import { useRef, useState } from "react"
import { Sparkles, Send } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import { aiDraft, ApiError } from "@/lib/api"

type Msg = { role: "user" | "assistant"; content: string }

const SYSTEM =
  "You are the RitaGreenOS staff assistant for RitaGreen, a UK group running care, training, " +
  "cleaning and security. Help office staff draft messages, summarise, and answer operational " +
  "questions. Be concise, practical, UK English."

// Slide-over AI assistant for staff. Every call routes through ai.php; the
// OpenRouter key stays server-side.
export function AiAssistant() {
  const [open, setOpen] = useState(false)
  const [messages, setMessages] = useState<Msg[]>([])
  const [input, setInput] = useState("")
  const [busy, setBusy] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)

  async function send(e: React.FormEvent) {
    e.preventDefault()
    const prompt = input.trim()
    if (!prompt || busy) return
    const next = [...messages, { role: "user" as const, content: prompt }]
    setMessages(next)
    setInput("")
    setBusy(true)
    try {
      const reply = await aiDraft(prompt, SYSTEM)
      setMessages([...next, { role: "assistant", content: reply }])
    } catch (err) {
      setMessages([
        ...next,
        {
          role: "assistant",
          content: err instanceof ApiError ? `Sorry — ${err.message}` : "Sorry, something went wrong.",
        },
      ])
    } finally {
      setBusy(false)
      setTimeout(() => endRef.current?.scrollIntoView({ behavior: "smooth" }), 50)
    }
  }

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="ghost" size="icon" className="rounded-full" aria-label="AI assistant">
          <Sparkles className="size-5" />
        </Button>
      </SheetTrigger>
      <SheetContent side="right" className="flex w-[92vw] max-w-md flex-col">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <Sparkles className="size-4 text-primary" /> AI assistant
          </SheetTitle>
          <SheetDescription>Drafting and answers for staff. Routes server-side.</SheetDescription>
        </SheetHeader>

        <div className="-mx-2 flex-1 space-y-3 overflow-y-auto px-2 py-4">
          {messages.length === 0 && (
            <div className="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">
              Ask me to draft a client email, summarise feedback, write a shift note, or explain a
              compliance rule.
            </div>
          )}
          {messages.map((m, i) => (
            <div
              key={i}
              className={
                m.role === "user"
                  ? "ml-auto max-w-[85%] rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground"
                  : "mr-auto max-w-[90%] whitespace-pre-wrap rounded-lg bg-muted px-3 py-2 text-sm"
              }
            >
              {m.content}
            </div>
          ))}
          {busy && <div className="mr-auto rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">Thinking…</div>}
          <div ref={endRef} />
        </div>

        <form onSubmit={send} className="flex gap-2 border-t border-border pt-3">
          <Input value={input} onChange={(e) => setInput(e.target.value)} placeholder="Ask the assistant…" disabled={busy} />
          <Button type="submit" size="icon" disabled={busy || !input.trim()}>
            <Send className="size-4" />
          </Button>
        </form>
      </SheetContent>
    </Sheet>
  )
}
