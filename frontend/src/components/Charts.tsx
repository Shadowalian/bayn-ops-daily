type BarPoint = { label: string; value: number | null; hint?: string }

const COLORS = ['#1f6b4a', '#c47a2c', '#2c5aa0', '#8b3a3a', '#5a4a7a', '#3d6b6b', '#a05a2c']

export function BarChart({ data, height = 180 }: { data: BarPoint[]; height?: number }) {
  const width = 560
  const pad = { t: 16, r: 12, b: 36, l: 36 }
  const innerW = width - pad.l - pad.r
  const innerH = height - pad.t - pad.b
  const hasValues = data.some((d) => d.value != null && d.value > 0)
  const max = 100
  const gap = 8
  const barW = data.length ? Math.max(8, (innerW - gap * Math.max(data.length - 1, 0)) / data.length) : innerW

  return (
    <div className="chart-frame">
      {!hasValues && <p className="chart-empty">No completion data in this range yet — graphs fill once people lock and close days.</p>}
      <svg viewBox={`0 0 ${width} ${height}`} className="chart-svg" role="img" aria-label="Completion trend">
        {[0, 25, 50, 75, 100].map((tick) => {
          const y = pad.t + innerH - (tick / max) * innerH
          return (
            <g key={tick}>
              <line x1={pad.l} x2={width - pad.r} y1={y} y2={y} className="chart-grid" />
              <text x={pad.l - 6} y={y + 3} className="chart-axis" textAnchor="end">
                {tick}%
              </text>
            </g>
          )
        })}
        {data.map((d, i) => {
          const v = d.value ?? 0
          const h = Math.max((v / max) * innerH, d.value == null ? 0 : 2)
          const x = pad.l + i * (barW + gap)
          const y = pad.t + innerH - h
          const short = d.label.length >= 10 ? d.label.slice(5) : d.label
          return (
            <g key={d.label}>
              <rect
                x={x}
                y={y}
                width={barW}
                height={Math.max(h, d.value == null ? 0 : 2)}
                rx={3}
                className={d.value == null ? 'chart-bar chart-bar-empty' : 'chart-bar'}
              />
              <text x={x + barW / 2} y={height - 12} className="chart-axis" textAnchor="middle">
                {short}
              </text>
              <title>{`${d.label}: ${d.value == null ? '—' : `${d.value}%`}${d.hint ? ` (${d.hint})` : ''}`}</title>
            </g>
          )
        })}
      </svg>
    </div>
  )
}

export function DonutChart({
  slices,
  centerLabel,
}: {
  slices: Array<{ label: string; value: number }>
  centerLabel?: string
}) {
  const total = slices.reduce((s, x) => s + x.value, 0) || 1
  const r = 54
  const cx = 70
  const cy = 70
  const stroke = 18
  const c = 2 * Math.PI * r
  let offset = 0

  if (slices.length === 0) {
    return <p className="chart-empty">No slip reasons recorded yet.</p>
  }

  return (
    <div className="donut-wrap">
      <svg viewBox="0 0 140 140" className="chart-svg donut-svg" role="img" aria-label="Reason breakdown">
        <circle cx={cx} cy={cy} r={r} className="donut-track" strokeWidth={stroke} fill="none" />
        {slices.map((slice, i) => {
          const len = (slice.value / total) * c
          const el = (
            <circle
              key={slice.label}
              cx={cx}
              cy={cy}
              r={r}
              fill="none"
              stroke={COLORS[i % COLORS.length]}
              strokeWidth={stroke}
              strokeDasharray={`${len} ${c - len}`}
              strokeDashoffset={-offset}
              transform={`rotate(-90 ${cx} ${cy})`}
            >
              <title>{`${slice.label}: ${slice.value}`}</title>
            </circle>
          )
          offset += len
          return el
        })}
        <text x={cx} y={cy - 2} className="donut-center" textAnchor="middle">
          {centerLabel ?? total}
        </text>
        <text x={cx} y={cy + 14} className="donut-sub" textAnchor="middle">
          total
        </text>
      </svg>
      <ul className="donut-legend">
        {slices.map((s, i) => (
          <li key={s.label}>
            <span className="swatch" style={{ background: COLORS[i % COLORS.length] }} />
            {s.label} · {s.value}
          </li>
        ))}
      </ul>
    </div>
  )
}

export function Gauge({ value, label }: { value: number | null; label: string }) {
  const v = value ?? 0
  const r = 48
  const c = Math.PI * r
  const filled = (Math.min(100, Math.max(0, v)) / 100) * c

  return (
    <div className="gauge-card">
      <svg viewBox="0 0 120 80" className="chart-svg" aria-label={label}>
        <path d="M12 68 A48 48 0 0 1 108 68" className="gauge-track" fill="none" strokeWidth="12" />
        <path
          d="M12 68 A48 48 0 0 1 108 68"
          className="gauge-fill"
          fill="none"
          strokeWidth="12"
          strokeDasharray={`${filled} ${c}`}
        />
        <text x="60" y="62" className="gauge-value" textAnchor="middle">
          {value == null ? '—' : `${value}%`}
        </text>
      </svg>
      <p className="gauge-label">{label}</p>
    </div>
  )
}
