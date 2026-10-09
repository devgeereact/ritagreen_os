import { useState } from "react"
import { Link } from "react-router-dom"
import { Bell, LogIn, LogOut, MapPin, Phone, TriangleAlert, MessageSquareWarning } from "lucide-react"
import { useDocumentTitle } from "@/hooks/use-document-title"
import { useApi } from "@/hooks/use-api"
import { api } from "@/lib/api"
import { shiftTimes } from "@/lib/rota"
import { greeting, londonNow, mapLink, relativeDay } from "@/lib/portal"
import type { PortalHome as HomeData, PortalShift } from "@/lib/types"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { LocationNotice } from "@/components/worker/LocationNotice"
import { Chip, PortalCard, PortalHeader, PortalSection, PortalState } from "@/components/worker/portal-bits"
import { act } from "@/components/worker/portal-utils"

type Notice = { id: number; title: string; body: string | null; read_at: string | null; created_at: string }

function getPos(): Promise<{ lat: number | null; lng: number | null }> {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve({ lat: null, lng: null })
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => resolve({ lat: null, lng: null }),
      { timeout: 5000 },
    )
  })
}

/** The shift to put first: one in progress, else the next one booked. */
function nextShift(shifts: PortalShift[], now: string): PortalShift | null {
  const live = shifts.filter((s) => s.status !== "cancelled" && !s.clocked_out)
  return live.find((s) => s.clocked_in) ?? live.find((s) => s.status === "scheduled" && s.ends_at >= now) ?? null
}

export default function PortalHome() {
  useDocumentTitle("Home, RitaGreen staff portal")
  const { data, loading, error, reload } = useApi<HomeData>("me.php")
  const notices = useApi<{ notifications: Notice[]; unread: number }>("notifications.php")
  const now = londonNow()
  const today = now.slice(0, 10)
  const shift = data ? nextShift(data.shifts, now) : null
  const first = data?.worker.name.split(" ")[0] ?? ""

  return (
    <>
      <PortalHeader
        title={data ? `${greeting(new Date().getHours())}, ${first}` : "Home"}
        sub={new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" })}
      />
      <LocationNotice />
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={4}>
        {data && (
          <>
            {data.blockers.length > 0 && (
              <PortalCard className="border-destructive/50">
                <h2 className="flex items-center gap-2 font-semibold">
                  <TriangleAlert className="size-5 text-destructive" aria-hidden />
                  Before you can be booked
                </h2>
                <ul className="mt-3 space-y-3">
                  {data.blockers.map((b, i) => (
                    <li key={i} className="text-sm">
                      <p className="font-medium">{b.title}</p>
                      <p className="text-muted-foreground">{b.todo}</p>
                    </li>
                  ))}
                </ul>
                {data.blockers.some((b) => b.todo.includes("Records")) && (
                  <Button asChild variant="outline" className="mt-3 h-11 w-full">
                    <Link to="/m/records">Open Records</Link>
                  </Button>
                )}
              </PortalCard>
            )}

            {shift ? <NextShift shift={shift} today={today} now={now} onChange={reload} /> : (
              <PortalCard>
                <p className="text-sm font-semibold text-muted-foreground">Next shift</p>
                <p className="mt-1">Nothing booked yet.</p>
                <Link to="/m/rota" className="mt-2 inline-block text-sm font-semibold text-primary underline-offset-2 hover:underline">
                  See open shifts
                </Link>
              </PortalCard>
            )}

            {data.offers.length > 0 && (
              <PortalCard className="border-[#B7791F]">
                <p className="font-semibold">
                  {data.offers.length === 1 ? "A shift is offered to you" : `${data.offers.length} shifts are offered to you`}
                </p>
                <Button asChild className="mt-3 h-11 w-full">
                  <Link to="/m/rota">Reply in Rota</Link>
                </Button>
              </PortalCard>
            )}

            {data.training_due.length > 0 && (
              <PortalSection title="Training due" id="training-due">
                {data.training_due.map((t, i) => (
                  <PortalCard key={i} className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-medium">{t.label}</p>
                      <p className="text-sm text-muted-foreground tabular-nums">
                        {t.expires_on ? `${(t.days ?? 0) < 0 ? "Expired" : "Due by"} ${new Date(`${t.expires_on}T00:00:00Z`).toLocaleDateString("en-GB", { day: "numeric", month: "long", timeZone: "UTC" })}` : ""}
                      </p>
                    </div>
                    {(t.days ?? 0) < 0 ? <Chip tone="bad">Expired</Chip> : <Chip tone="wait">Due soon</Chip>}
                  </PortalCard>
                ))}
              </PortalSection>
            )}

            <Messages data={notices.data} onRead={notices.reload} />
          </>
        )}
      </PortalState>
    </>
  )
}

function NextShift({ shift, today, now, onChange }: { shift: PortalShift; today: string; now: string; onChange: () => void }) {
  const [busy, setBusy] = useState<string | null>(null)
  const [reporting, setReporting] = useState(false)
  const map = mapLink(shift.site_address, shift.site_postcode)
  const opensAt = shift.clock_in_opens_at
  const canClockIn = !shift.clocked_in && shift.status === "scheduled" && now >= opensAt
  const onSite = shift.clocked_in === 1 && shift.clocked_out === 0

  async function field(kind: "clock_in" | "clock_out" | "check_in" | "panic", saved: string) {
    setBusy(kind)
    const pos = await getPos()
    const ok = await act(() => api("field.php", { method: "POST", body: { shift_id: shift.id, kind, lat: pos.lat, lng: pos.lng } }), saved)
    setBusy(null)
    if (ok && kind !== "check_in" && kind !== "panic") onChange()
  }

  return (
    <PortalCard>
      <p className="text-sm font-semibold text-muted-foreground">{onSite ? "On shift now" : "Next shift"}</p>
      <p className="mt-0.5 text-2xl font-semibold leading-tight tabular-nums">
        {relativeDay(shift.starts_at, today)}
        <br />
        {shiftTimes(shift.starts_at, shift.ends_at)}
      </p>
      <p className="mt-2 font-semibold">{shift.site_name ?? shift.title}</p>
      {shift.site_name && <p className="text-sm text-muted-foreground">{shift.title}</p>}
      {(shift.site_address || shift.site_postcode) && (
        <div className="mt-1 flex items-center justify-between gap-2 text-[15px] text-muted-foreground">
          <span className="min-w-0">{[shift.site_address, shift.site_postcode].filter(Boolean).join(", ")}</span>
          {map && (
            <a
              href={map}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 rounded"
            >
              <MapPin className="size-4" aria-hidden /> Open map
            </a>
          )}
        </div>
      )}
      {shift.site_contact && (
        <p className="mt-1 text-sm">
          Site contact: {shift.site_contact}
          {shift.site_contact_phone && (
            <> · <a className="inline-flex items-center gap-1 font-semibold text-primary underline-offset-2 hover:underline" href={`tel:${shift.site_contact_phone.replace(/\s+/g, "")}`}>
              <Phone className="size-3.5" aria-hidden />{shift.site_contact_phone}
            </a></>
          )}
        </p>
      )}
      {shift.site_notes && (
        <p className="mt-3 rounded-md bg-background px-3 py-2.5 text-sm"><span className="font-semibold">Site notes:</span> {shift.site_notes}</p>
      )}

      <div className="mt-4 space-y-2">
        {!shift.clocked_in && (
          <>
            <Button className="h-12 w-full text-base" disabled={!canClockIn || busy !== null} onClick={() => field("clock_in", "Clocked in.")}>
              <LogIn className="mr-2 size-5" aria-hidden /> {busy === "clock_in" ? "Clocking in…" : "Clock in"}
            </Button>
            {!canClockIn && (
              <p className="text-center text-sm text-muted-foreground tabular-nums">
                Available from {relativeDay(opensAt, today) === "Today" ? "" : `${relativeDay(opensAt, today)} at `}{opensAt.slice(11, 16)}
              </p>
            )}
          </>
        )}
        {onSite && (
          <>
            <Button className="h-12 w-full text-base" disabled={busy !== null} onClick={() => field("clock_out", "Clocked out. Check your timesheet in Records.")}>
              <LogOut className="mr-2 size-5" aria-hidden /> {busy === "clock_out" ? "Clocking out…" : "Clock out"}
            </Button>
            <Button variant="outline" className="h-11 w-full" disabled={busy !== null} onClick={() => field("check_in", "Check call recorded.")}>
              <MapPin className="mr-2 size-4" aria-hidden /> I am safe (check call)
            </Button>
          </>
        )}
        <div className="grid grid-cols-2 gap-2">
          <Button variant="outline" className="h-11" onClick={() => setReporting(true)}>
            <MessageSquareWarning className="mr-1.5 size-4" aria-hidden /> Report a problem
          </Button>
          <Button
            variant="outline"
            className="h-11 border-destructive/50 text-destructive hover:bg-destructive/10"
            disabled={busy !== null}
            onClick={() => {
              if (window.confirm("Send a panic alert to the office now?")) void field("panic", "Alert sent. The office has been told.")
            }}
          >
            <TriangleAlert className="mr-1.5 size-4" aria-hidden /> Panic alert
          </Button>
        </div>
      </div>
      <ReportDialog open={reporting} onOpenChange={setReporting} shiftId={shift.id} />
    </PortalCard>
  )
}

function ReportDialog({ open, onOpenChange, shiftId }: { open: boolean; onOpenChange: (v: boolean) => void; shiftId: number }) {
  const [note, setNote] = useState("")
  const [busy, setBusy] = useState(false)
  async function send() {
    setBusy(true)
    const ok = await act(() => api("me.php?action=report", { method: "POST", body: { shift_id: shiftId, note } }),
      "Sent. The office has been told.")
    setBusy(false)
    if (ok) {
      setNote("")
      onOpenChange(false)
    }
  }
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[calc(100vw-2rem)] sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Report a problem</DialogTitle>
          <DialogDescription>
            The office is told straight away. If anyone is in danger, call 999 first, then use the panic alert.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-1.5">
          <Label htmlFor="report-note">What has happened?</Label>
          <Textarea id="report-note" rows={4} maxLength={400} value={note} onChange={(e) => setNote(e.target.value)} />
          <p className="text-right text-xs text-muted-foreground tabular-nums">{note.length}/400</p>
        </div>
        <DialogFooter>
          <Button className="h-11 w-full" disabled={busy || note.trim() === ""} onClick={send}>
            {busy ? "Sending…" : "Send to the office"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function Messages({ data, onRead }: { data: { notifications: Notice[]; unread: number } | null; onRead: () => void }) {
  if (!data) return null
  const list = data.notifications.slice(0, 5)
  return (
    <PortalSection title={data.unread > 0 ? `Messages (${data.unread} unread)` : "Messages"} id="messages">
      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">No messages yet. Shift changes and reminders appear here.</p>
      ) : (
        <PortalCard className="divide-y divide-border p-0">
          {list.map((n) => (
            <div key={n.id} className="flex gap-3 px-4 py-3">
              <Bell className={n.read_at ? "mt-0.5 size-4 text-muted-foreground" : "mt-0.5 size-4 text-primary"} aria-hidden />
              <div className="min-w-0">
                <p className={n.read_at ? "text-sm" : "text-sm font-semibold"}>
                  {n.title}
                  {!n.read_at && <span className="sr-only"> (unread)</span>}
                </p>
                {n.body && <p className="text-sm text-muted-foreground [overflow-wrap:anywhere]">{n.body}</p>}
              </div>
            </div>
          ))}
        </PortalCard>
      )}
      {data.unread > 0 && (
        <Button
          variant="ghost"
          className="h-11 w-full"
          onClick={() => act(() => api("notifications.php?action=read_all", { method: "POST" }), "Marked as read.").then((ok) => ok && onRead())}
        >
          Mark all as read
        </Button>
      )}
    </PortalSection>
  )
}

