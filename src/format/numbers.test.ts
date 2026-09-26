import { describe, expect, it } from 'vitest'
import {
  UNKNOWN_VALUE,
  formatAcceleration,
  formatConsumption,
  formatDisplacement,
  formatLitres,
  formatPower,
} from './numbers'

const NBSP = ' '

describe('formatAcceleration', () => {
  it('renders exactly one fraction digit', () => {
    expect(formatAcceleration(9.4)).toBe('9,4 s')
  })

  it('keeps a trailing ,0 for an integral value', () => {
    expect(formatAcceleration(11)).toBe('11,0 s')
  })
})

describe('formatConsumption', () => {
  it('renders exactly one fraction digit', () => {
    expect(formatConsumption(4.9)).toBe('4,9 l/100 km')
  })

  it('renders null as unknown', () => {
    expect(formatConsumption(null)).toBe(UNKNOWN_VALUE)
  })
})

describe('formatPower', () => {
  it('derives kW from hp and omits the decimal for an integral value', () => {
    expect(formatPower(110)).toBe('110 KM (81 kW)')
  })

  it('keeps a fractional hp value', () => {
    expect(formatPower(110.5)).toBe('110,5 KM (81 kW)')
  })

  it('renders null as unknown', () => {
    expect(formatPower(null)).toBe(UNKNOWN_VALUE)
  })
})

describe('formatDisplacement', () => {
  it('groups thousands with a non-breaking separator', () => {
    expect(formatDisplacement(1896)).toBe(`1${NBSP}896 cm³`)
  })

  it('renders null as unknown', () => {
    expect(formatDisplacement(null)).toBe(UNKNOWN_VALUE)
  })
})

describe('formatLitres', () => {
  it('renders a whole number of litres', () => {
    expect(formatLitres(62)).toBe('62 l')
    expect(formatLitres(440)).toBe('440 l')
  })

  it('renders null as unknown', () => {
    expect(formatLitres(null)).toBe(UNKNOWN_VALUE)
  })
})
