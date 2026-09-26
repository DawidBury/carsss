import { afterEach, describe, expect, it } from 'vitest'
import type { Variant } from '../api/types'
import { UNKNOWN_VALUE } from '../format/numbers'
import fullVariant from '../api/fixtures/variant.json'
import nullTankVariant from '../api/fixtures/variant-null-tank.json'
import nullGenBodyVariant from '../api/fixtures/variant-null-gen-body.json'
import unresolvedSegmentVariant from '../api/fixtures/variant-unresolved-segment.json'
import nullDriveVariant from '../api/fixtures/variant-null-drive.json'
import nullGearboxVariant from '../api/fixtures/variant-null-gearbox.json'
import { buildShareUrl, buildSpecGroups, buildSpecSheetHeader } from './rows'

function asVariant(json: unknown): Variant {
  return json as Variant
}

const NBSP = ' '

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe('buildSpecGroups', () => {
  it('renders all three groups in order with every field filled', () => {
    const groups = buildSpecGroups(asVariant(fullVariant))

    expect(groups.map((group) => group.label)).toEqual([
      'Silnik',
      'Napęd i skrzynia',
      'Osiągi i eksploatacja',
    ])
    expect(groups[0]?.rows).toEqual([
      { label: 'Pojemność skokowa', value: `1${NBSP}896 cm³`, isUnknown: false },
      { label: 'Moc', value: '110 KM (81 kW)', isUnknown: false },
      { label: 'Rodzaj paliwa', value: 'diesel', isUnknown: false },
    ])
    expect(groups[1]?.rows).toEqual([
      { label: 'Skrzynia biegów', value: 'manualna', isUnknown: false },
      { label: 'Napęd', value: 'przedni', isUnknown: false },
    ])
    expect(groups[2]?.rows).toEqual([
      { label: '0–100 km/h', value: '11,3 s', isUnknown: false },
      { label: 'Spalanie średnie', value: '4,9 l/100 km', isUnknown: false },
      { label: 'Pojemność zbiornika', value: '62 l', isUnknown: false },
      { label: 'Pojemność bagażnika', value: '440 l', isUnknown: false },
    ])
  })

  it('marks a null tank and boot capacity as unknown rather than zero', () => {
    const groups = buildSpecGroups(asVariant(nullTankVariant))

    const tankRow = groups[2]?.rows.find((r) => r.label === 'Pojemność zbiornika')
    const bootRow = groups[2]?.rows.find((r) => r.label === 'Pojemność bagażnika')

    expect(tankRow).toEqual({
      label: 'Pojemność zbiornika',
      value: UNKNOWN_VALUE,
      isUnknown: true,
    })
    expect(bootRow).toEqual({
      label: 'Pojemność bagażnika',
      value: UNKNOWN_VALUE,
      isUnknown: true,
    })
  })

  it('marks a null gearbox as unknown while still rendering drive', () => {
    const groups = buildSpecGroups(asVariant(nullGearboxVariant))

    expect(groups[1]?.rows).toEqual([
      { label: 'Skrzynia biegów', value: UNKNOWN_VALUE, isUnknown: true },
      { label: 'Napęd', value: 'przedni', isUnknown: false },
    ])
  })

  it('never omits a row for a null value', () => {
    const groups = buildSpecGroups(asVariant(nullGearboxVariant))

    expect(groups.flatMap((group) => group.rows)).toHaveLength(9)
  })
})

describe('buildSpecSheetHeader', () => {
  it('builds the kicker, title and full meta line when everything is known', () => {
    const header = buildSpecSheetHeader(asVariant(fullVariant))

    expect(header.kicker).toBe('Audi · A4')
    expect(header.title).toBe('1.9 TDI 110KM 81kW 1995-2000')
    expect(header.meta).toBe('B5 · Sedan · manualna · przedni')
  })

  it('omits the version segment when generation, body and unresolved are all null', () => {
    const header = buildSpecSheetHeader(asVariant(nullGenBodyVariant))

    expect(header.meta).toBe('manualna · przedni')
  })

  it('uses the unresolved segment when generation and body are absent', () => {
    const header = buildSpecSheetHeader(asVariant(unresolvedSegmentVariant))

    expect(header.meta).toBe('II · automatyczna · przedni')
  })

  it('omits a null drive from the meta line without leaving a dash', () => {
    const header = buildSpecSheetHeader(asVariant(nullDriveVariant))

    expect(header.meta).toBe('Hatchback 3d · manualna')
  })

  it('omits a null gearbox from the meta line without leaving a dash', () => {
    const header = buildSpecSheetHeader(asVariant(nullGearboxVariant))

    expect(header.meta).toBe('II · Hatchback · przedni')
  })
})

describe('buildShareUrl', () => {
  it('concatenates the current origin, pathname and the variant id', () => {
    window.history.replaceState(null, '', '/wyszukiwarka')

    expect(buildShareUrl('abc-123')).toBe(
      'http://localhost:3000/wyszukiwarka?v=abc-123',
    )
  })
})
