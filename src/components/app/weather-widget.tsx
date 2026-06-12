import {
  Sun,
  Cloud,
  CloudRain,
  CloudLightning,
  CloudSnow,
  CloudFog,
  Wind,
  Droplets,
  RefreshCw,
} from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { useApi } from "@/hooks/use-api"
import { cn } from "@/lib/utils"

type Advisory = { level: "ok" | "watch" | "alert"; note: string }
type Weather = {
  city: string
  units: string
  temp: number
  feels_like: number
  main: string
  description: string
  humidity: number
  wind_mph: number
  advisory: Advisory
}

function iconFor(main: string) {
  switch (main) {
    case "Clear":
      return Sun
    case "Rain":
    case "Drizzle":
      return CloudRain
    case "Thunderstorm":
      return CloudLightning
    case "Snow":
      return CloudSnow
    case "Mist":
    case "Fog":
    case "Haze":
    case "Smoke":
      return CloudFog
    default:
      return Cloud
  }
}

const TONE: Record<Advisory["level"], { dot: string; text: string; bg: string }> = {
  ok: { dot: "bg-primary", text: "text-primary", bg: "bg-secondary/50" },
  watch: { dot: "bg-amber-500", text: "text-amber-700", bg: "bg-amber-50" },
  alert: { dot: "bg-destructive", text: "text-destructive", bg: "bg-destructive/5" },
}

const degree = (units: string) => (units === "imperial" ? "°F" : units === "standard" ? "K" : "°C")

// Operational weather for the office. Degrades quietly when the API isn't
// configured — it's a helpful extra, never a blocker on the dashboard.
export function WeatherWidget() {
  const { data, loading, error, reload } = useApi<Weather>("weather.php")

  if (error) return null // not configured / unreachable — stay out of the way

  const Icon = data ? iconFor(data.main) : Cloud
  const tone = data ? TONE[data.advisory.level] : TONE.ok

  return (
    <Card className={cn("overflow-hidden", data && tone.bg)}>
      <CardContent className="flex flex-col gap-4 pt-6 sm:flex-row sm:items-center">
        {loading && !data ? (
          <div className="flex items-center gap-3 text-sm text-muted-foreground">
            <RefreshCw className="size-4 animate-spin" /> Checking conditions…
          </div>
        ) : data ? (
          <>
            <div className="flex items-center gap-4">
              <Icon className="size-10 shrink-0 text-primary" />
              <div>
                <div className="flex items-baseline gap-2">
                  <span className="text-3xl font-bold">
                    {data.temp}
                    {degree(data.units)}
                  </span>
                  <span className="text-sm capitalize text-muted-foreground">
                    {data.description}
                  </span>
                </div>
                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                  <span>{data.city}</span>
                  <span className="flex items-center gap-1">
                    <Wind className="size-3" /> {data.wind_mph} mph
                  </span>
                  <span className="flex items-center gap-1">
                    <Droplets className="size-3" /> {data.humidity}%
                  </span>
                  <span>Feels {data.feels_like}{degree(data.units)}</span>
                </div>
              </div>
            </div>

            <div className="flex flex-1 items-start gap-2 sm:border-l sm:border-border/60 sm:pl-4">
              <span className={cn("mt-1.5 size-2 shrink-0 rounded-full", tone.dot)} />
              <div>
                <p className={cn("text-xs font-semibold uppercase tracking-wide", tone.text)}>
                  {data.advisory.level === "ok"
                    ? "Operations: clear"
                    : data.advisory.level === "watch"
                      ? "Operations: take care"
                      : "Operations: alert"}
                </p>
                <p className="mt-0.5 text-sm text-foreground">{data.advisory.note}</p>
              </div>
            </div>

            <button
              onClick={reload}
              className="self-start text-muted-foreground transition-colors hover:text-foreground"
              aria-label="Refresh weather"
              title="Refresh"
            >
              <RefreshCw className={cn("size-4", loading && "animate-spin")} />
            </button>
          </>
        ) : null}
      </CardContent>
    </Card>
  )
}
