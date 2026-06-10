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
      <div className="badge">Launching soon</div>

      <h1>RitaGreen OS</h1>
      <p className="tagline">
        Something fresh is growing. We're putting the finishing touches on it.
      </p>

      {submitted ? (
        <p className="thanks">Thanks — you're on the list. We'll be in touch.</p>
      ) : (
        <form className="notify" onSubmit={handleSubmit}>
          <input
            type="email"
            required
            placeholder="you@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-label="Email address"
          />
          <button type="submit">Notify me</button>
        </form>
      )}

      <footer className="foot">
        <span>© {new Date().getFullYear()} RitaGreen</span>
      </footer>
    </main>
  )
}

export default App
