import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

/**
 * The minimum rest between two shifts for one worker (app_settings
 * rest_gap_min_hours, default 11). A shorter gap is not refused: the rota warns
 * and the office acknowledges it when booking (server/compliance-lib.php).
 *
 * The office can read it; only an owner (super_admin, admin) can change it, and
 * server/scheduling.php?action=settings enforces that and the 0 to 24 bounds.
 */
export function RestGapCard({ canEdit }: { canEdit: boolean }) {
  const { data, loading, error, reload } = useApi<{ rest_gap_min_hours: number }>("scheduling.php?action=settings")
  const [hours, setHours] = useState("")
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (data) setHours(String(data.rest_gap_min_hours))
  }, [data])

  async function save(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setSaving(true)
    try {
      const res = await api<{ rest_gap_min_hours: number }>("scheduling.php?action=settings", {
        method: "POST",
        body: { rest_gap_min_hours: hours.trim() },
      })
      toast.success(`Minimum rest set to ${res.rest_gap_min_hours} hours.`)
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "The minimum rest could not be saved.")
    } finally {
      setSaving(false)
    }
  }

  const unchanged = data !== null && hours.trim() === String(data.rest_gap_min_hours)

  return (
    <Card className="max-w-3xl p-6">
      <h2 className="text-lg font-semibold">Rota rules</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        The minimum rest between one shift ending and the next starting for the same worker. A shorter gap is not
        refused: the rota warns, and whoever books it must acknowledge the warning.
        {!canEdit && " Only an owner can change this."}
      </p>
      {loading && !data && <div className="mt-4"><LoadingBlock rows={1} /></div>}
      {error && !data && <div className="mt-4"><ErrorBlock message={error} onRetry={reload} /></div>}
      {data && (
        <form onSubmit={save} className="mt-4 flex flex-wrap items-end gap-3">
          <div className="grid gap-2">
            <Label htmlFor="rest-gap-hours">Minimum rest, in hours</Label>
            <Input
              id="rest-gap-hours"
              type="number"
              inputMode="numeric"
              min={0}
              max={24}
              step={1}
              required
              value={hours}
              onChange={(e) => setHours(e.target.value)}
              disabled={!canEdit || saving}
              className="w-32"
            />
          </div>
          {canEdit && (
            <Button type="submit" disabled={saving || unchanged}>
              {saving ? "Saving" : "Save"}
            </Button>
          )}
        </form>
      )}
    </Card>
  )
}
