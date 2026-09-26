import type { Drive, FuelType, Gearbox } from '../api/types'
import { UNKNOWN_VALUE } from './numbers'

export const gearboxLabels: Record<Gearbox, string> = {
  manual: 'manualna',
  automatic: 'automatyczna',
}

export const driveLabels: Record<Drive, string> = {
  front: 'przedni',
  rear: 'tylny',
  all: '4×4',
}

export const fuelTypeLabels: Record<FuelType, string> = {
  petrol: 'benzyna',
  diesel: 'diesel',
  hybrid: 'hybryda',
  electric: 'elektryczny',
}

export function formatGearbox(gearbox: Gearbox | null): string {
  if (gearbox === null) return UNKNOWN_VALUE
  return gearboxLabels[gearbox]
}

export function formatDrive(drive: Drive | null): string {
  if (drive === null) return UNKNOWN_VALUE
  return driveLabels[drive]
}

export function formatFuelType(fuelType: FuelType): string {
  return fuelTypeLabels[fuelType]
}

export function formatVariantDiscriminator(
  gearbox: Gearbox | null,
  drive: Drive | null,
): string {
  const parts: string[] = []
  if (gearbox !== null) parts.push(gearboxLabels[gearbox])
  if (drive !== null) parts.push(driveLabels[drive])
  return parts.join(' · ')
}

export const specSheetGroupLabels = {
  engine: 'Silnik',
  drivetrain: 'Napęd i skrzynia',
  performance: 'Osiągi i eksploatacja',
} as const

export const specSheetRowLabels = {
  displacement: 'Pojemność skokowa',
  power: 'Moc',
  fuelType: 'Rodzaj paliwa',
  gearbox: 'Skrzynia biegów',
  drive: 'Napęd',
  acceleration: '0–100 km/h',
  consumption: 'Spalanie średnie',
  fuelTank: 'Pojemność zbiornika',
  bootCapacity: 'Pojemność bagażnika',
} as const

export const searchStatusMessages = {
  error: 'Nie udało się pobrać podpowiedzi',
  empty: 'Brak podpowiedzi',
} as const

export function formatResultCount(count: number): string {
  if (count === 1) return 'Znaleziono 1 podpowiedź'
  return `Znaleziono ${count} podpowiedzi`
}
