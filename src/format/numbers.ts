export const UNKNOWN_VALUE = '—'

export const HP_TO_KW = 0.7355

const accelerationFormatter = new Intl.NumberFormat('pl-PL', {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
  useGrouping: true,
})

const consumptionFormatter = new Intl.NumberFormat('pl-PL', {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
  useGrouping: true,
})

const powerFormatter = new Intl.NumberFormat('pl-PL', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 1,
  useGrouping: true,
})

const integerFormatter = new Intl.NumberFormat('pl-PL', {
  maximumFractionDigits: 0,
  useGrouping: true,
})

export function formatAcceleration(seconds: number): string {
  return `${accelerationFormatter.format(seconds)} s`
}

export function formatConsumption(litresPer100Km: number | null): string {
  if (litresPer100Km === null) return UNKNOWN_VALUE
  return `${consumptionFormatter.format(litresPer100Km)} l/100 km`
}

export function formatPower(horsepower: number | null): string {
  if (horsepower === null) return UNKNOWN_VALUE
  const kilowatts = Math.round(horsepower * HP_TO_KW)
  return `${powerFormatter.format(horsepower)} KM (${integerFormatter.format(kilowatts)} kW)`
}

export function formatDisplacement(cubicCentimetres: number | null): string {
  if (cubicCentimetres === null) return UNKNOWN_VALUE
  return `${integerFormatter.format(cubicCentimetres)} cm³`
}

export function formatLitres(litres: number | null): string {
  if (litres === null) return UNKNOWN_VALUE
  return `${integerFormatter.format(litres)} l`
}
