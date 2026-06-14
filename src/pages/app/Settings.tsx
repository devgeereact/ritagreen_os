import { useState } from "react"
import { Plus, Lock } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useAuth } from "@/providers/auth-provider"
import { useApi } from "@/hooks/use-api"
import { api, ApiError } from "@/lib/api"
import type { AdminUser, AuditEntry } from "@/lib/types"
import { shortDate } from "@/lib/format"
import { LoadingBlock, ErrorBlock, EmptyBlock } from "@/components/app/state-block"

const ROLES = ["super_admin", "admin", "manager", "trainer", "editor", "employee", "client"]
const DIVISIONS = ["care", "academy", "cleaning", "security"]

export default function Settings() {
  const { hasRole } = useAuth()

  if (!hasRole("super_admin", "admin")) {
    return (
      <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border py-20 text-center">
        <Lock className="size-8 text-muted-foreground" />
        <p className="mt-3 font-medium">Admins only</p>
        <p className="mt-1 max-w-sm text-sm text-muted-foreground">
          User management and the audit log are restricted to admin roles.
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Settings & admin</h1>
        <p className="text-sm text-muted-foreground">Manage people, roles and review what's happened.</p>
      </div>

      <Tabs defaultValue="users">
        <TabsList>
          <TabsTrigger value="users">Users & roles</TabsTrigger>
          <TabsTrigger value="audit">Audit log</TabsTrigger>
          <TabsTrigger value="gdpr">Data & GDPR</TabsTrigger>
        </TabsList>
        <TabsContent value="users" className="mt-4">
          <UsersTab />
        </TabsContent>
        <TabsContent value="audit" className="mt-4">
          <AuditTab />
        </TabsContent>
        <TabsContent value="gdpr" className="mt-4">
          <GdprTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function UsersTab() {
  const { user: me } = useAuth()
  const { data, loading, error, reload } = useApi<{ users: AdminUser[] }>("users.php")
  const [open, setOpen] = useState(false)

  async function deactivate(id: number) {
    try {
      await api(`users.php?action=deactivate&id=${id}`, { method: "POST", body: {} })
      toast.success("User deactivated.")
      reload()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not deactivate the user.")
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <NewUserDialog open={open} onOpenChange={setOpen} onCreated={reload} />
      </div>
      {loading && <LoadingBlock rows={4} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Division</TableHead>
                <TableHead>Status</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.users.map((u) => (
                <TableRow key={u.id}>
                  <TableCell className="font-medium">{u.name}</TableCell>
                  <TableCell className="text-muted-foreground">{u.email}</TableCell>
                  <TableCell><Badge variant="secondary" className="capitalize">{u.role.replace("_", " ")}</Badge></TableCell>
                  <TableCell className="capitalize text-muted-foreground">{u.division || "—"}</TableCell>
                  <TableCell>
                    <Badge variant={u.active ? "default" : "outline"}>{u.active ? "Active" : "Inactive"}</Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    {u.active && u.id !== me?.id && (
                      <Button size="sm" variant="outline" onClick={() => deactivate(u.id)}>
                        Deactivate
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}
    </div>
  )
}

function AuditTab() {
  const { data, loading, error, reload } = useApi<{ entries: AuditEntry[] }>("audit.php")
  return (
    <div className="space-y-4">
      {loading && <LoadingBlock rows={6} />}
      {error && <ErrorBlock message={error} onRetry={reload} />}
      {data && !loading && (
        data.entries.length === 0 ? (
          <EmptyBlock title="Nothing logged yet" hint="Sensitive actions appear here as they happen." />
        ) : (
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>When</TableHead>
                  <TableHead>Who</TableHead>
                  <TableHead>Action</TableHead>
                  <TableHead>Detail</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.entries.map((e) => (
                  <TableRow key={e.id}>
                    <TableCell className="whitespace-nowrap text-muted-foreground">{shortDate(e.created_at)}</TableCell>
                    <TableCell>{e.user || "—"}</TableCell>
                    <TableCell><code className="text-xs">{e.action}</code></TableCell>
                    <TableCell className="text-muted-foreground">{e.detail}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        )
      )}
    </div>
  )
}

function GdprTab() {
  const [email, setEmail] = useState("")
  const [busy, setBusy] = useState(false)

  async function exportData() {
    if (!email) {
      toast.error("Enter an email.")
      return
    }
    setBusy(true)
    try {
      const data = await api<Record<string, unknown>>("gdpr.php?action=export", { method: "POST", body: { email } })
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" })
      const url = URL.createObjectURL(blob)
      const a = document.createElement("a")
      a.href = url
      a.download = `gdpr-export-${email}.json`
      a.click()
      URL.revokeObjectURL(url)
      toast.success("Data export downloaded.")
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not export.")
    } finally {
      setBusy(false)
    }
  }

  async function erase() {
    if (!email) {
      toast.error("Enter an email.")
      return
    }
    if (!window.confirm(`Erase public-form personal data for ${email}? This cannot be undone.`)) return
    setBusy(true)
    try {
      const r = await api<{ erased: Record<string, number>; needs_manual_review: Record<string, unknown[]> }>(
        "gdpr.php?action=erase",
        { method: "POST", body: { email } },
      )
      const total = Object.values(r.erased).reduce((a, b) => a + b, 0)
      const manual = (r.needs_manual_review.clients?.length ?? 0) + (r.needs_manual_review.workers?.length ?? 0)
      toast.success(`Erased ${total} record(s).${manual ? ` ${manual} employment record(s) flagged for manual review.` : ""}`)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not erase.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="max-w-2xl p-6">
      <h3 className="text-base font-semibold">Data subject requests</h3>
      <p className="mt-1 text-sm text-muted-foreground">
        Export or erase the personal data held for an email address (UK GDPR access &amp; erasure).
        Employment and contract records are flagged for manual review, not auto-deleted.
      </p>
      <div className="mt-4 flex flex-wrap items-end gap-2">
        <div className="grid flex-1 gap-2">
          <Label htmlFor="gdpr-email">Email address</Label>
          <Input id="gdpr-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="person@example.com" />
        </div>
        <Button variant="outline" onClick={exportData} disabled={busy}>Export data</Button>
        <Button variant="destructive" onClick={erase} disabled={busy}>Erase</Button>
      </div>
    </Card>
  )
}

function NewUserDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  onCreated: () => void
}) {
  const [role, setRole] = useState("employee")
  const [division, setDivision] = useState("none")
  const [submitting, setSubmitting] = useState(false)

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const payload = {
      name: String(form.get("name") || "").trim(),
      email: String(form.get("email") || "").trim(),
      password: String(form.get("password") || ""),
      role,
      division: division === "none" ? "" : division,
    }
    if (!payload.name || !payload.email || payload.password.length < 8) {
      toast.error("Name, email and a password of at least 8 characters are required.")
      return
    }
    setSubmitting(true)
    try {
      await api("users.php", { method: "POST", body: payload })
      toast.success("User created.")
      onOpenChange(false)
      onCreated()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not create the user.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button><Plus className="mr-1 size-4" /> Add user</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add user</DialogTitle>
          <DialogDescription>Set an initial password; the user can change it after first login.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" name="email" type="email" required />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="role">Role</Label>
              <Select value={role} onValueChange={setRole}>
                <SelectTrigger id="role"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {ROLES.map((r) => (
                    <SelectItem key={r} value={r} className="capitalize">{r.replace("_", " ")}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="division">Division</Label>
              <Select value={division} onValueChange={setDivision}>
                <SelectTrigger id="division"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {DIVISIONS.map((d) => (
                    <SelectItem key={d} value={d} className="capitalize">{d}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="password">Initial password</Label>
            <Input id="password" name="password" type="password" minLength={8} required />
          </div>
          <DialogFooter>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Create user"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
