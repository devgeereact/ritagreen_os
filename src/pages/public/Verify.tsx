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
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<Certificate | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [qr, setQr] = useState("")

  useEffect(() => {
    if (!result) {
      setQr("")
      return
    }
    QRCode.toDataURL(`${window.location.origin}/verify/${result.id}`)
      .then(setQr)
      .catch(() => setQr(""))
  }, [result])

  useEffect(() => {
    if (!id) {
      setResult(null)
      setNotFound(false)
      return
    }
    setLoading(true)
    setNotFound(false)
    api<{ certificate: Certificate }>(`certificates.php?action=verify&id=${encodeURIComponent(id)}`, {
      auth: false,
    })
      .then((res) => setResult(res.certificate))
      .catch((err) => {
        setResult(null)
        if (err instanceof ApiError && err.status === 404) setNotFound(true)
        else setNotFound(true)
      })
      .finally(() => setLoading(false))
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
                {qr && (
                  <div className="text-center">
                    <img src={qr} alt="Verification QR code" className="size-28 rounded-lg border bg-white p-1.5" />
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
