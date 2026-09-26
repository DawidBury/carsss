import { describe, expect, it } from 'vitest'
import type { Drive, FuelType, Gearbox } from '../api/types'
import { UNKNOWN_VALUE } from './numbers'
import {
  driveLabels,
  formatDrive,
  formatFuelType,
  formatGearbox,
  formatVariantDiscriminator,
  fuelTypeLabels,
  gearboxLabels,
} from './labels'

const gearboxes: Gearbox[] = ['manual', 'automatic']
const drives: Drive[] = ['front', 'rear', 'all']
const fuelTypes: FuelType[] = ['petrol', 'diesel', 'hybrid', 'electric']

describe('formatGearbox', () => {
  it.each(gearboxes)('maps every member (%s)', (gearbox) => {
    expect(formatGearbox(gearbox)).toBe(gearboxLabels[gearbox])
  })

  it('renders null as unknown', () => {
    expect(formatGearbox(null)).toBe(UNKNOWN_VALUE)
  })
})

describe('formatDrive', () => {
  it.each(drives)('maps every member (%s)', (drive) => {
    expect(formatDrive(drive)).toBe(driveLabels[drive])
  })

  it('renders null as unknown', () => {
    expect(formatDrive(null)).toBe(UNKNOWN_VALUE)
  })
})

describe('formatFuelType', () => {
  it.each(fuelTypes)('maps every member (%s)', (fuelType) => {
    expect(formatFuelType(fuelType)).toBe(fuelTypeLabels[fuelType])
  })
})

describe('formatVariantDiscriminator', () => {
  it('joins gearbox and drive with a middle dot', () => {
    expect(formatVariantDiscriminator('manual', 'front')).toBe(
      'manualna · przedni',
    )
  })

  it('omits a null gearbox rather than dashing it', () => {
    expect(formatVariantDiscriminator(null, 'front')).toBe('przedni')
  })

  it('omits a null drive rather than dashing it', () => {
    expect(formatVariantDiscriminator('automatic', null)).toBe('automatyczna')
  })

  it('renders an empty string when both are null', () => {
    expect(formatVariantDiscriminator(null, null)).toBe('')
  })
})
