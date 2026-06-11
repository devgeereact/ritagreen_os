import { useEffect, useState } from "react"
import { useNavigate, useParams } from "react-router-dom"
import { BadgeCheck, XCircle, Search } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { PageHeader, Section } from "@/components/public/section"
import { api, ApiError } from "@/lib/api"
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
            <div className="rounded-xl border border-primary/30 bg-accent p-6">
              <div className="flex items-center gap-2 text-primary">
                <BadgeCheck className="size-6" />
                <span className="font-semibold">Valid certificate</span>
              </div>
              <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt className="text-muted-foreground">Certificate ID</dt><dd className="font-medium">{result.id}</dd></div>
                <div><dt className="text-muted-foreground">Learner</dt><dd className="font-medium">{result.learner_name}</dd></div>
                <div><dt className="text-muted-foreground">Course</dt><dd className="font-medium">{result.course}</dd></div>
                <div><dt className="text-muted-foreground">Issued</dt><dd className="font-medium">{result.issued_on}</dd></div>
              </dl>
            </div>
          )}

          {!loading && notFound && (
            <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-6">
              <div className="flex items-center gap-2 text-destructive">
                <XCircle className="size-6" />
                <span className="font-semibold">No certificate found</span>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">
                We couldn't find a certificate with that ID. Check the ID and try again, or contact us.
              </p>
            </div>
          )}
        </div>
      </Section>
    </>
  )
}
