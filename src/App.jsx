import { useState } from 'react'
import './App.css'

function App() {
  const [email, setEmail] = useState('')
  const [submitted, setSubmitted] = useState(false)

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!email) return
    // TODO: wire to api.php endpoint when backend ready
    setSubmitted(true)
  }

  return (
    <main className="coming-soon">
      <header className="brand">
        <span className="brand-mark" aria-hidden="true" />
        <span className="brand-name">RitaGreen</span>
      </header>

      <div className="hero">
        <p className="eyebrow">Launching soon</p>
        <h1>Something fresh is growing.</h1>
        <p className="tagline">
          We're putting the finishing touches on RitaGreen OS. Leave your email
          and we'll let you know the moment it's live.
        </p>

        {submitted ? (
          <p className="thanks" role="status">
            <span className="thanks-check" aria-hidden="true" />
            You're on the list — we'll be in touch.
          </p>
        ) : (
          <form className="notify" onSubmit={handleSubmit} noValidate>
            <label className="visually-hidden" htmlFor="email">
              Email address
            </label>
            <input
              id="email"
              type="email"
              required
              autoComplete="email"
              placeholder="you@example.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
            <button type="submit">Notify me</button>
          </form>
        )}
      </div>

      <footer className="foot">
        © {new Date().getFullYear()} RitaGreen. All rights reserved.
      </footer>
    </main>
  )
}

export default App
