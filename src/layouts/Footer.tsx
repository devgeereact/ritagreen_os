export default function Footer() {
  return (
    <footer className="border-t">
      <div className="container flex h-14 items-center justify-between text-sm text-muted-foreground">
        <span>
          © {new Date().getFullYear()}{" "}
          <span className="font-medium text-foreground">RitaGreen Services Ltd</span>. All rights reserved.
        </span>
        <span>RitaGreenOS · internal platform</span>
      </div>
    </footer>
  )
}
