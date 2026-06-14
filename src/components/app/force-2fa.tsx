import { useEffect, useState } from "react"
import QRCode from "qrcode"
import { ShieldAlert } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { api, ApiError } from "@/lib/api"

// Blocking overlay shown when policy requires this (privileged) user to enrol in
// 2FA before using the app. Dismisses by enabling 2FA; reload re-reads /me.
export function Force2FA() {
  const [secret, setSecret] = useState("")
  const [qr, setQr] = useState("")
  const [code, setCode] = useState("")
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api<{ secret: string; otpauth: string }>("auth.php?action=2fa_setup", { method: "POST", body: {} })
      .then(async (r) => {
        setSecret(r.secret)
        setQr(await QRCode.toDataURL(r.otpauth))
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not start setup."))
  }, [])

  async function enable() {
    setBusy(true)
    setError(null)
    try {
      await api("auth.php?action=2fa_enable", { method: "POST", body: { code } })
      window.location.reload() // /me now returns must_setup_2fa = false
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That code was not valid.")
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center bg-background/95 p-6 backdrop-blur">
      <div className="w-full max-w-sm rounded-xl border bg-card p-6 shadow-xl">
        <div className="flex flex-col items-center text-center">
          <span className="grid size-12 place-items-center rounded-full bg-destructive/10 text-destructive"><ShieldAlert className="size-6" /></span>
          <h2 className="mt-3 text-lg font-bold">Set up 2FA to continue</h2>
          <p className="mt-1 text-sm text-muted-foreground">Your role requires two-factor authentication. Scan the code, then enter a 6-digit code.</p>
        </div>
        {error && <p className="mt-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
        {qr && <img src={qr} alt="2FA QR code" className="mx-auto mt-4 size-40 rounded-lg border bg-white p-2" />}
        {secret && <p className="mt-2 break-all text-center text-xs text-muted-foreground">Manual key: <code className="rounded bg-muted px-1">{secret}</code></p>}
        <div className="mt-4 grid gap-2">
          <Label htmlFor="f2fa-code">6-digit code</Label>
          <Input id="f2fa-code" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))} autoFocus />
          <Button onClick={enable} disabled={busy || code.length !== 6}>{busy ? "Verifying…" : "Enable & continue"}</Button>
        </div>
      </div>
    </div>
  )
}
