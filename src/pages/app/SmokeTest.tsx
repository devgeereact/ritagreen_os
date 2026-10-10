import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import { LoadingBlock, ErrorBlock } from "@/components/app/state-block"

type Item = { id: number; name: string; created_at: string }

// Build-order step 1: prove the React -> PHP -> MySQL pipe end to end before
// trusting any feature. GET api.php and POST one item. The ai.php ping went
// when ai.php was retired in S9.
export default function SmokeTest() {
  const { data, loading, error, reload } = useApi<{ items: Item[] }>("api.php")
  const [name, setName] = useState("")

  async function addItem(e: React.FormEvent) {
    e.preventDefault()
    if (!name.trim()) return
    try {
      await api("api.php", { method: "POST", body: { name: name.trim() }, auth: false })
      toast.success("Item posted.")
      setName("")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "POST failed.")
    }
  }

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Smoke test</h1>
        <p className="text-sm text-muted-foreground">Confirms React → PHP → MySQL.</p>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-base">Items (api.php)</CardTitle></CardHeader>
        <CardContent className="space-y-4">
          <form onSubmit={addItem} className="flex gap-2">
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="New item name" />
            <Button type="submit">Post</Button>
          </form>
          {loading && <LoadingBlock rows={2} />}
          {error && <ErrorBlock message={error} onRetry={reload} />}
          {data && !loading && (
            data.items.length === 0 ? (
              <p className="text-sm text-muted-foreground">GET returned an empty list. The pipe works.</p>
            ) : (
              <ul className="divide-y divide-border rounded-md border border-border">
                {data.items.map((it) => (
                  <li key={it.id} className="flex justify-between px-3 py-2 text-sm">
                    <span>{it.name}</span>
                    <span className="text-muted-foreground">#{it.id}</span>
                  </li>
                ))}
              </ul>
            )
          )}
        </CardContent>
      </Card>
    </div>
  )
}
