import { useEffect, useState } from "react"
import { useParams } from "react-router-dom"
import { Loader2, CheckCircle2, XCircle, CalendarClock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { api, ApiError } from "@/lib/api"
import { DIVISION_LABELS } from "@/lib/format"

type OfferData = {
  offer: { status: string; worker: string }
  shift: {
    title: string
    division: string
    site_name: string | null
    client: string | null
    starts_at: string
    ends_at: string
    status: string
  }
}

function when(dt: string): string {
  const d = new Date(dt.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return dt
  return d.toLocaleString("en-GB", { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })
}

export default function Offer() {
  const { token } = useParams()
  const [data, setData] = useState<OfferData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<{ kind: string; message: string } | null>(null)

  useEffect(() => {
    api<OfferData>(`offer.php?token=${token}`, { auth: false })
      .then(setData)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load this offer."))
  }, [token])

  async function respond(action: "accept" | "decline") {
    setBusy(true)
    try {
      const r = await api<{ result: string; message: string }>(`offer.php?token=${token}`, {
        method: "POST",
        body: { action },
        auth: false,
      })
      setResult({ kind: r.result, message: r.message })
    } catch (err) {
      setResult({ kind: "error", message: err instanceof ApiError ? err.message : "Something went wrong." })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-[70vh] max-w-md flex-col justify-center px-4 py-10">
      <div className="mb-6 text-center">
        <span className="text-xl font-bold">
          <span className="text-foreground">Rita</span>
          <span className="text-primary">Green</span>
        </span>
        <p className="mt-1 text-sm text-muted-foreground">Shift offer</p>
      </div>

      {error && (
        <div className="rounded-xl border bg-card p-6 text-center text-sm text-destructive">{error}</div>
      )}

      {!data && !error && (
        <div className="flex justify-center py-10"><Loader2 className="size-6 animate-spin text-muted-foreground" /></div>
      )}

      {data && (
        <div className="rounded-xl border bg-card p-6 shadow-sm">
          <div className="flex items-center gap-2 text-primary">
            <CalendarClock className="size-5" />
            <h1 className="text-lg font-semibold">{data.shift.title}</h1>
          </div>
          <dl className="mt-4 space-y-2 text-sm">
            <Row label="Division" value={DIVISION_LABELS[data.shift.division] ?? data.shift.division} />
            {data.shift.client && <Row label="Client" value={data.shift.client} />}
            {data.shift.site_name && <Row label="Site" value={data.shift.site_name} />}
            <Row label="Starts" value={when(data.shift.starts_at)} />
            <Row label="Ends" value={when(data.shift.ends_at)} />
            <Row label="Offered to" value={data.offer.worker} />
          </dl>

          {result ? (
            <div className="mt-6 flex flex-col items-center gap-2 text-center">
              {result.kind === "accepted" ? (
                <CheckCircle2 className="size-10 text-primary" />
              ) : (
                <XCircle className="size-10 text-muted-foreground" />
              )}
              <p className="text-sm font-medium">{result.message}</p>
            </div>
          ) : data.offer.status !== "sent" || data.shift.status !== "open" ? (
            <p className="mt-6 rounded-lg bg-muted p-3 text-center text-sm text-muted-foreground">
              This shift is no longer available.
            </p>
          ) : (
            <div className="mt-6 grid grid-cols-2 gap-3">
              <Button variant="outline" onClick={() => respond("decline")} disabled={busy}>
                Decline
              </Button>
              <Button onClick={() => respond("accept")} disabled={busy}>
                {busy ? <Loader2 className="mr-1 size-4 animate-spin" /> : null} Accept shift
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-right font-medium">{value}</dd>
    </div>
  )
}
