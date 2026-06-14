import { Mail, Shield, IdCard, Building2 } from "lucide-react"
import { Card } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Link } from "react-router-dom"
import { useAuth } from "@/providers/auth-provider"

// Self-service identity page. Account details are managed by an admin in
// Settings; this is the member's read-only view of who they are in the system.
export default function Profile() {
  const { user } = useAuth()

  const initials = user?.name
    ? user.name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase()
    : "RG"
  const role = user?.role?.replace(/_/g, " ") ?? "—"

  const rows = [
    { icon: IdCard, label: "Full name", value: user?.name ?? "—" },
    { icon: Mail, label: "Email", value: user?.email ?? "—" },
    { icon: Shield, label: "Role", value: role, isRole: true },
    { icon: Building2, label: "Account type", value: user?.client_id ? "Client" : user?.worker_id ? "Worker" : "Staff" },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Profile</h1>
        <p className="mt-1 text-sm text-muted-foreground">Your account and identity across RitaGreen.</p>
      </div>

      {/* Identity header */}
      <Card className="flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center">
        <Avatar className="h-16 w-16 rounded-xl">
          <AvatarFallback className="rounded-xl bg-primary text-lg font-semibold text-primary-foreground">
            {initials}
          </AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <p className="truncate text-lg font-semibold">{user?.name ?? "Member"}</p>
          <p className="truncate text-sm text-muted-foreground">{user?.email}</p>
          <Badge variant="secondary" className="mt-2 capitalize">{role}</Badge>
        </div>
        <Button asChild variant="outline" size="sm">
          <Link to="/app/settings">Account settings</Link>
        </Button>
      </Card>

      {/* Detail rows */}
      <Card className="divide-y divide-border p-0">
        {rows.map((r) => (
          <div key={r.label} className="flex items-center gap-4 px-6 py-4">
            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
              <r.icon className="size-4" />
            </span>
            <span className="w-40 shrink-0 text-sm text-muted-foreground">{r.label}</span>
            <span className={"truncate text-sm font-medium" + (r.isRole ? " capitalize" : "")}>{r.value}</span>
          </div>
        ))}
      </Card>

      <p className="text-xs text-muted-foreground">
        Need to change your name, email, or role? These are managed by an administrator in{" "}
        <Link to="/app/settings" className="font-medium text-primary hover:underline">Settings</Link>.
      </p>
    </div>
  )
}
