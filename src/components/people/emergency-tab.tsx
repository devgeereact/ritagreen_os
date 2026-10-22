import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { usePostAction } from "@/hooks/use-post-action"
import { isRestricted } from "@/lib/people"
import type { EmergencyContacts, PersonProfile } from "@/lib/types"
import { Fact, RestrictedBlock, Section } from "@/components/people/shared"

const EMPTY: EmergencyContacts = {
  emergency_contact_name: null, emergency_contact_phone: null, emergency_contact_relationship: null,
  emergency_contact2_name: null, emergency_contact2_phone: null, emergency_contact2_relationship: null,
}

/**
 * Who to ring if something happens to this person at work. Visible to
 * authorised office staff and the person only, edited by the office here and by
 * the worker in their own portal, under the same rules (server/people-lib.php).
 */
export function EmergencyTab({ profile, onChanged }: { profile: PersonProfile; onChanged: () => void }) {
  const contacts = profile.emergency
  const [editing, setEditing] = useState(false)
  if (isRestricted(contacts)) return <RestrictedBlock what="emergency contacts" />

  if (editing) {
    return <EmergencyForm personId={profile.person.id} initial={contacts} onDone={() => { setEditing(false); onChanged() }} onCancel={() => setEditing(false)} />
  }

  const none = !contacts.emergency_contact_name && !contacts.emergency_contact2_name
  return (
    <Section
      title="Emergency contacts"
      description="Visible to authorised office staff and the person only."
      action={profile.can.edit_emergency ? <Button size="sm" variant="outline" onClick={() => setEditing(true)}>{none ? "Add" : "Edit"}</Button> : undefined}
    >
      {none ? (
        <p className="text-sm text-muted-foreground">No emergency contact recorded.</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          <ContactCard title="First contact" name={contacts.emergency_contact_name} phone={contacts.emergency_contact_phone} relationship={contacts.emergency_contact_relationship} />
          <ContactCard title="Second contact" name={contacts.emergency_contact2_name} phone={contacts.emergency_contact2_phone} relationship={contacts.emergency_contact2_relationship} />
        </div>
      )}
    </Section>
  )
}

function ContactCard({ title, name, phone, relationship }: { title: string; name: string | null; phone: string | null; relationship: string | null }) {
  return (
    <div className="rounded-md border border-border p-3">
      <p className="text-sm font-medium">{title}</p>
      {name ? (
        <dl className="mt-2 grid gap-2">
          <Fact label="Name">{name}</Fact>
          <Fact label="Relationship">{relationship ?? "Not given"}</Fact>
          <Fact label="Phone">{phone ? <a className="text-primary underline-offset-2 hover:underline" href={`tel:${phone.replace(/[^\d+]/g, "")}`}>{phone}</a> : "Not given"}</Fact>
        </dl>
      ) : (
        <p className="mt-2 text-sm text-muted-foreground">Not recorded.</p>
      )}
    </div>
  )
}

function EmergencyForm({ personId, initial, onDone, onCancel }: {
  personId: number
  initial: EmergencyContacts
  onDone: () => void
  onCancel: () => void
}) {
  const [values, setValues] = useState<EmergencyContacts>({ ...EMPTY, ...initial })
  const { run, busy } = usePostAction(onDone)
  const set = (key: keyof EmergencyContacts) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setValues((v) => ({ ...v, [key]: e.target.value }))

  return (
    <Section title="Edit emergency contacts" description="Leave a contact empty to remove it. Each contact needs a name and a number.">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          void run(`people.php?action=emergency&id=${personId}`, values, "Emergency contacts saved.")
        }}
      >
        {(["", "2"] as const).map((n) => (
          <fieldset key={n} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-3">
            <legend className="px-1 text-sm font-medium">{n === "" ? "First contact" : "Second contact"}</legend>
            <div className="space-y-1.5">
              <Label htmlFor={`ec${n}-name`}>Name</Label>
              <Input id={`ec${n}-name`} maxLength={160} autoComplete="off"
                value={values[`emergency_contact${n}_name`] ?? ""} onChange={set(`emergency_contact${n}_name`)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={`ec${n}-rel`}>Relationship</Label>
              <Input id={`ec${n}-rel`} maxLength={80} autoComplete="off"
                value={values[`emergency_contact${n}_relationship`] ?? ""} onChange={set(`emergency_contact${n}_relationship`)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={`ec${n}-phone`}>Phone</Label>
              <Input id={`ec${n}-phone`} type="tel" inputMode="tel" maxLength={40} autoComplete="off"
                value={values[`emergency_contact${n}_phone`] ?? ""} onChange={set(`emergency_contact${n}_phone`)} />
            </div>
          </fieldset>
        ))}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? "Saving…" : "Save"}</Button>
        </div>
      </form>
    </Section>
  )
}
