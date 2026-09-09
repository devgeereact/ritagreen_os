import { useEffect, useState } from "react"
import { useNavigate, useParams } from "react-router-dom"
import QRCode from "qrcode"
import { BadgeCheck, XCircle, Search } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { PageHeader, Section } from "@/components/public/section"
import { api, API_BASE, ApiError } from "@/lib/api"
import { useSeo } from "@/hooks/use-seo"

type Certificate = {
  id: string
  learner_name: string
  course: string
  issued_on: string
  status: string
}

// Public certificate verification — no login. Employers check a RitaGreen
// academy certificate is genuine by its id.
export default function Verify() {
  useSeo({
    title: "Verify a certificate — RitaGreen Academy",
    description:
      "Confirm a RitaGreen Academy certificate is genuine. Enter the certificate ID to see the learner, course and issue date.",
    path: "/verify",
  })

  const { id } = useParams()
  const navigate = useNavigate()
  const [query, setQuery] = useState(id ?? "")

  // One state value, stamped with the id it belongs to.
  //
  // It was three (`loading`, `result`, `notFound`), two of which had to be reset
  // synchronously inside the effect when the URL carried no id — the cascading
  // render react-hooks warns about. Stamping the outcome with its id lets the
  // "nothing to show" case be derived at render instead, and stops a previous
  // certificate's card showing for a moment while the next one is fetched.
  type Lookup =
    | { id: string; status: "loading" }
    | { id: string; status: "found"; certificate: Certificate }
    | { id: string; status: "missing" }
    | { id: string; status: "unavailable" }
  const [lookup, setLookup] = useState<Lookup | null>(null)
  const [qr, setQr] = useState<{ id: string; url: string } | null>(null)

  const current = id && lookup?.id === id ? lookup : null
  const result = current?.status === "found" ? current.certificate : null
  const loading = current?.status === "loading"
  const notFound = current?.status === "missing"
  // A certificate that could not be checked is not a certificate that does not
  // exist. Both used to say "No certificate found", so somebody holding a
  // genuine certificate on a dropped connection was told it was not real.
  const unavailable = current?.status === "unavailable"
  const qrUrl = result && qr?.id === result.id ? qr.url : ""

  useEffect(() => {
    if (!result) return
    let alive = true
    QRCode.toDataURL(`${window.location.origin}/verify/${result.id}`)
      .then((url) => {
        if (alive) setQr({ id: result.id, url })
      })
      .catch(() => {})
    return () => {
      alive = false
    }
  }, [result])

  useEffect(() => {
    if (!id) return
    let alive = true
    // Queued as a microtask rather than set inline: an effect body that calls
    // setState synchronously renders twice before the browser paints.
    void Promise.resolve().then(() => {
      if (alive) setLookup({ id, status: "loading" })
    })
    api<{ certificate: Certificate }>(`certificates.php?action=verify&id=${encodeURIComponent(id)}`, {
      auth: false,
    })
      .then((res) => {
        if (alive) setLookup({ id, status: "found", certificate: res.certificate })
      })
      .catch((err) => {
        if (!alive) return
        const genuinely404 = err instanceof ApiError && err.status === 404
        setLookup({ id, status: genuinely404 ? "missing" : "unavailable" })
      })
    return () => {
      alive = false
    }
  }, [id])

  function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (query.trim()) navigate(`/verify/${encodeURIComponent(query.trim())}`)
  }

  return (
    <>
      <PageHeader
        eyebrow="Certificate verification"
        title="Check a RitaGreen Academy certificate"
        intro="Enter the certificate ID printed on the certificate to confirm it is genuine."
      />
      <Section className="max-w-2xl">
        <form onSubmit={onSubmit} className="flex gap-2">
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="e.g. RG-2026-00123"
            aria-label="Certificate ID"
          />
          <Button type="submit">
            <Search className="mr-1 size-4" /> Verify
          </Button>
        </form>

        <div className="mt-8">
          {loading && <p className="text-muted-foreground">Checking…</p>}

          {!loading && result && (
            <div className="surface overflow-hidden border-primary/30 shadow-elevate animate-fade-up">
              <div className="flex items-center gap-3 border-b border-primary/20 bg-primary/10 px-6 py-4 text-primary">
                <BadgeCheck className="size-6" />
                <span className="font-semibold">Valid certificate</span>
              </div>
              <div className="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between">
                <dl className="grid flex-1 gap-5 text-sm sm:grid-cols-2">
                  <div><dt className="text-xs uppercase tracking-wide text-muted-foreground">Certificate ID</dt><dd className="mt-1 font-medium">{result.id}</dd></div>
                  <div><dt className="text-xs uppercase tracking-wide text-muted-foreground">Learner</dt><dd className="mt-1 font-medium">{result.learner_name}</dd></div>
                  <div><dt className="text-xs uppercase tracking-wide text-muted-foreground">Course</dt><dd className="mt-1 font-medium">{result.course}</dd></div>
                  <div><dt className="text-xs uppercase tracking-wide text-muted-foreground">Issued</dt><dd className="mt-1 font-medium">{result.issued_on}</dd></div>
                </dl>
                {qrUrl && (
                  <div className="text-center">
                    <img src={qrUrl} alt="Verification QR code" className="size-28 rounded-lg border bg-white p-1.5" />
                    <p className="mt-1 text-[10px] text-muted-foreground">Scan to verify</p>
                  </div>
                )}
              </div>
              <div className="flex flex-wrap gap-3 border-t border-primary/10 px-6 py-4 text-sm">
                <a
                  className="font-medium text-primary hover:underline"
                  target="_blank"
                  rel="noopener noreferrer"
                  href={
                    "https://www.linkedin.com/profile/add?" +
                    new URLSearchParams({
                      startTask: "CERTIFICATION_NAME",
                      name: result.course,
                      organizationName: "RitaGreen Academy",
                      certId: result.id,
                      certUrl: `${window.location.origin}/verify/${result.id}`,
                    }).toString()
                  }
                >
                  Add to LinkedIn
                </a>
                <a
                  className="font-medium text-primary hover:underline"
                  target="_blank"
                  rel="noopener noreferrer"
                  href={`${API_BASE}/certificates.php?action=badge&id=${encodeURIComponent(result.id)}`}
                >
                  Open Badge (JSON)
                </a>
              </div>
            </div>
          )}

          {!loading && unavailable && (
            <div className="surface overflow-hidden border-warning/30 animate-fade-up">
              <div className="flex items-center gap-3 border-b border-warning/20 bg-warning/10 px-6 py-4 text-warning">
                <XCircle className="size-6" />
                <span className="font-semibold">Could not check this certificate</span>
              </div>
              <p className="p-6 text-sm leading-relaxed text-muted-foreground">
                We could not reach the verification service. This does not mean the certificate is
                invalid — check your connection and try again, or contact us.
              </p>
            </div>
          )}

          {!loading && notFound && (
            <div className="surface overflow-hidden border-destructive/30 animate-fade-up">
              <div className="flex items-center gap-3 border-b border-destructive/20 bg-destructive/10 px-6 py-4 text-destructive">
                <XCircle className="size-6" />
                <span className="font-semibold">No certificate found</span>
              </div>
              <p className="p-6 text-sm leading-relaxed text-muted-foreground">
                We couldn't find a certificate with that ID. Check the ID and try again, or contact us.
              </p>
            </div>
          )}
        </div>
      </Section>
    </>
  )
}
