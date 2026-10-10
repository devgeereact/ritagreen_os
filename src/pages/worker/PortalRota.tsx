import { useState } from "react"
import { CalendarPlus } from "lucide-react"
import { useDocumentTitle } from "@/hooks/use-document-title"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { dayLabel, shiftTimes } from "@/lib/rota"
import { byDay, downloadIcs, londonNow, relativeDay } from "@/lib/portal"
import { DIVISION_LABELS } from "@/lib/format"
import type { PortalHome, PortalOffer, PortalOpenShift, PortalShift } from "@/lib/types"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Chip, Empty, PortalCard, PortalHeader, PortalSection, PortalState } from "@/components/worker/portal-bits"
import { act } from "@/components/worker/portal-utils"

/** "Today, Friday 9 October", "Tomorrow, Saturday 10 October" or "Monday 12 October". */
function dayHeading(date: string, today: string): string {
  const rel = relativeDay(`${date} 00:00:00`, today)
  return rel === "Today" || rel === "Tomorrow" ? `${rel}, ${dayLabel(date, true)}` : dayLabel(date, true)
}

function shiftChip(s: PortalShift, now: string) {
  if (s.status === "cancelled") return <Chip tone="bad">Cancelled</Chip>
  if (s.clocked_out || s.status === "completed") return <Chip tone="quiet">Completed</Chip>
  if (s.clocked_in) return <Chip tone="ok">On site</Chip>
  if (s.ends_at < now) return <Chip tone="quiet">Ended</Chip>
  return <Chip tone="ok">Confirmed</Chip>
}

export default function PortalRota() {
  useDocumentTitle("Rota, RitaGreen staff portal")
  const { data, loading, error, reload } = useApi<PortalHome>("me.php")
  const now = londonNow()
  const today = now.slice(0, 10)
  const booked = data?.shifts.filter((s) => s.status === "scheduled" && !s.clocked_out && s.ends_at >= now) ?? []

  return (
    <>
      <PortalHeader
        title="Rota"
        sub="Your published shifts, offers and open shifts"
        action={booked.length > 0 ? (
          <Button
            variant="outline"
            className="h-11 shrink-0"
            onClick={() => {
              downloadIcs(booked)
              toast.success("Calendar file ready. Open it to add your shifts.")
            }}
          >
            <CalendarPlus className="mr-1.5 size-4" aria-hidden /> Add to calendar
          </Button>
        ) : undefined}
      />
      <PortalState loading={loading} error={error} onRetry={reload} hasData={!!data} rows={5}>
        {data && (
          <>
            {data.offers.length > 0 && (
              <PortalSection title="Offers waiting for you" id="offers">
                {data.offers.map((o) => <OfferCard key={o.id} offer={o} today={today} onDone={reload} />)}
              </PortalSection>
            )}

            <PortalSection title="My shifts" id="my-shifts">
              {data.shifts.length === 0 ? (
                <Empty>No shifts booked. Open shifts you can pick up are below.</Empty>
              ) : (
                byDay(data.shifts).map((g) => (
                  <div key={g.date} className="space-y-2">
                    <h3 className="px-0.5 text-sm font-semibold text-muted-foreground">{dayHeading(g.date, today)}</h3>
                    {g.shifts.map((s) => (
                      <PortalCard key={s.id} className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="font-semibold tabular-nums">{shiftTimes(s.starts_at, s.ends_at)}</p>
                          <p className="text-sm text-muted-foreground">{[s.site_name, s.title].filter(Boolean).join(", ")}</p>
                          {s.status === "cancelled" && s.cancel_reason && (
                            <p className="mt-1 text-sm">Reason: {s.cancel_reason}</p>
                          )}
                        </div>
                        {shiftChip(s, now)}
                      </PortalCard>
                    ))}
                  </div>
                ))
              )}
            </PortalSection>

            {/* A shift offered to me by name is answered above, not picked up below. */}
            <OpenShifts
              shifts={data.open_shifts.filter((s) => !data.offers.some((o) => o.shift_id === s.id))}
              today={today}
              onDone={reload}
            />
          </>
        )}
      </PortalState>
    </>
  )
}

function OfferCard({ offer, today, onDone }: { offer: PortalOffer; today: string; onDone: () => void }) {
  const [busy, setBusy] = useState(false)
  async function answer(kind: "accept" | "decline") {
    setBusy(true)
    await act(
      () => api(`me.php?action=offer_${kind}&id=${offer.id}`, { method: "POST" }),
      kind === "accept" ? "Accepted. The shift is in your rota." : "Declined. The office will offer it to someone else.",
    )
    setBusy(false)
    // A refusal changes the offer too (it lapses), so read again either way.
    onDone()
  }
  return (
    <PortalCard className="border-[#B7791F]">
      <p className="text-sm font-semibold text-[#7A5115]">
        {offer.expires_at ? `Offer, reply by ${relativeDay(offer.expires_at, today)} ${offer.expires_at.slice(11, 16)}` : "Offer"}
      </p>
      <p className="mt-1 text-xl font-semibold leading-tight tabular-nums">
        {relativeDay(offer.starts_at, today)}
        <br />
        {shiftTimes(offer.starts_at, offer.ends_at)}
      </p>
      <p className="mt-2 font-semibold">{offer.site_name ?? offer.title}</p>
      <p className="text-sm text-muted-foreground">
        {[offer.site_name ? offer.title : null, DIVISION_LABELS[offer.division] ?? offer.division].filter(Boolean).join(", ")}
      </p>
      <div className="mt-4 grid grid-cols-2 gap-2">
        <Button className="h-11" disabled={busy} onClick={() => answer("accept")}>Accept</Button>
        <Button variant="secondary" className="h-11" disabled={busy} onClick={() => answer("decline")}>Decline</Button>
      </div>
    </PortalCard>
  )
}

function OpenShifts({ shifts, today, onDone }: { shifts: PortalOpenShift[]; today: string; onDone: () => void }) {
  const [busy, setBusy] = useState<number | null>(null)
  // A refusal the server gave just now, kept on the card so it can be read
  // after the toast has gone.
  const [refused, setRefused] = useState<Record<number, string>>({})
  const eligible = shifts.filter((s) => s.eligible)
  const notForMe = shifts.filter((s) => !s.eligible)

  async function pickUp(id: number) {
    setBusy(id)
    try {
      await api(`me.php?action=accept&shift_id=${id}`, { method: "POST", body: {} })
      toast.success("Picked up. The shift is in your rota.")
      onDone()
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : "That did not work. Please try again."
      toast.error(msg)
      setRefused((r) => ({ ...r, [id]: msg }))
    } finally {
      setBusy(null)
    }
  }

  return (
    <PortalSection title="Open shifts you can pick up" id="open-shifts">
      {eligible.length === 0 ? (
        <Empty>No open shifts for you right now.</Empty>
      ) : (
        eligible.map((s) => (
          <PortalCard key={s.id}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="font-semibold tabular-nums">{relativeDay(s.starts_at, today)}, {shiftTimes(s.starts_at, s.ends_at)}</p>
                <p className="text-sm text-muted-foreground">{[s.site_name ?? s.client, s.title].filter(Boolean).join(", ")}</p>
              </div>
              <Button className="h-11 shrink-0" disabled={busy !== null} onClick={() => pickUp(s.id)}>
                {busy === s.id ? "Checking…" : "Pick up"}
              </Button>
            </div>
            {refused[s.id] && (
              <p role="alert" className="mt-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">
                {refused[s.id]}
              </p>
            )}
          </PortalCard>
        ))
      )}
      {notForMe.length > 0 && (
        <details className="rounded-lg border border-border bg-card p-4">
          <summary className="cursor-pointer text-sm font-semibold">
            {notForMe.length} open {notForMe.length === 1 ? "shift" : "shifts"} you cannot take, and why
          </summary>
          <ul className="mt-3 space-y-3">
            {notForMe.map((s) => (
              <li key={s.id} className="text-sm">
                <p className="font-medium tabular-nums">{relativeDay(s.starts_at, today)}, {shiftTimes(s.starts_at, s.ends_at)}</p>
                <p className="text-muted-foreground">{s.reason}</p>
              </li>
            ))}
          </ul>
        </details>
      )}
    </PortalSection>
  )
}
