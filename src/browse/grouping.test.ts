import { describe, expect, it } from 'vitest'
import rawVariantFixture from '../api/fixtures/variant.json'
import type { Drive, Gearbox, Variant } from '../api/types'
import { groupVariantsByVersion } from './grouping'

const variantFixture = rawVariantFixture as Variant

function makeVariant(options: {
  id: string
  versionId: string
  versionName: string
  gearbox: Gearbox | null
  drive: Drive | null
}): Variant {
  return {
    ...variantFixture,
    id: options.id,
    gearbox: options.gearbox,
    drive: options.drive,
    version: {
      ...variantFixture.version,
      id: options.versionId,
      name: options.versionName,
    },
  }
}

describe('groupVariantsByVersion', () => {
  it('groups variants under their version', () => {
    const variants = [
      makeVariant({
        id: 'v1',
        versionId: 'ver-1',
        versionName: '1.9 TDI 110KM',
        gearbox: 'manual',
        drive: 'front',
      }),
      makeVariant({
        id: 'v2',
        versionId: 'ver-1',
        versionName: '1.9 TDI 110KM',
        gearbox: 'automatic',
        drive: 'front',
      }),
      makeVariant({
        id: 'v3',
        versionId: 'ver-2',
        versionName: '2.0 TDI 140KM',
        gearbox: 'manual',
        drive: 'front',
      }),
    ]

    const groups = groupVariantsByVersion(variants)

    expect(groups).toHaveLength(2)
    expect(groups[0]?.versionId).toBe('ver-1')
    expect(groups[0]?.options).toHaveLength(2)
    expect(groups[1]?.versionId).toBe('ver-2')
    expect(groups[1]?.options).toHaveLength(1)
  })

  it('gives a version with a single variant a group with one option', () => {
    const groups = groupVariantsByVersion([
      makeVariant({
        id: 'v1',
        versionId: 'ver-1',
        versionName: 'Solo',
        gearbox: 'manual',
        drive: 'front',
      }),
    ])

    expect(groups).toHaveLength(1)
    expect(groups[0]?.options).toHaveLength(1)
  })

  it('merges variants of the same version arriving out of order, as across a page boundary', () => {
    const variants = [
      makeVariant({
        id: 'v1',
        versionId: 'ver-1',
        versionName: 'Split version',
        gearbox: 'manual',
        drive: 'front',
      }),
      makeVariant({
        id: 'v2',
        versionId: 'ver-2',
        versionName: 'Other version',
        gearbox: 'manual',
        drive: 'front',
      }),
      makeVariant({
        id: 'v3',
        versionId: 'ver-1',
        versionName: 'Split version',
        gearbox: 'automatic',
        drive: 'front',
      }),
    ]

    const groups = groupVariantsByVersion(variants)
    const split = groups.find((group) => group.versionId === 'ver-1')

    expect(split?.options.map((option) => option.variantId)).toEqual([
      'v1',
      'v3',
    ])
  })

  it('orders version groups by name with Intl.Collator numeric semantics', () => {
    const variants = [
      makeVariant({
        id: 'v1',
        versionId: 'ver-2',
        versionName: '2.0 TDI',
        gearbox: 'manual',
        drive: 'front',
      }),
      makeVariant({
        id: 'v2',
        versionId: 'ver-10',
        versionName: '10.0 TDI',
        gearbox: 'manual',
        drive: 'front',
      }),
      makeVariant({
        id: 'v3',
        versionId: 'ver-1',
        versionName: '1.9 TDI',
        gearbox: 'manual',
        drive: 'front',
      }),
    ]

    const groups = groupVariantsByVersion(variants)

    expect(groups.map((group) => group.versionName)).toEqual([
      '1.9 TDI',
      '2.0 TDI',
      '10.0 TDI',
    ])
  })

  it('orders options manual before automatic, then front, rear, all', () => {
    const variants = [
      makeVariant({
        id: 'all',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: 'manual',
        drive: 'all',
      }),
      makeVariant({
        id: 'automatic-front',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: 'automatic',
        drive: 'front',
      }),
      makeVariant({
        id: 'rear',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: 'manual',
        drive: 'rear',
      }),
      makeVariant({
        id: 'front',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: 'manual',
        drive: 'front',
      }),
    ]

    const groups = groupVariantsByVersion(variants)

    expect(groups[0]?.options.map((option) => option.variantId)).toEqual([
      'front',
      'rear',
      'all',
      'automatic-front',
    ])
  })

  it('sorts a null gearbox or drive after every known value', () => {
    const variants = [
      makeVariant({
        id: 'null-gearbox',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: null,
        drive: 'front',
      }),
      makeVariant({
        id: 'manual',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: 'manual',
        drive: 'front',
      }),
    ]

    const groups = groupVariantsByVersion(variants)

    expect(groups[0]?.options.map((option) => option.variantId)).toEqual([
      'manual',
      'null-gearbox',
    ])
  })

  it('builds the option label from gearbox and drive, omitting a null attribute', () => {
    const groups = groupVariantsByVersion([
      makeVariant({
        id: 'v1',
        versionId: 'ver-1',
        versionName: 'V',
        gearbox: 'manual',
        drive: null,
      }),
    ])

    expect(groups[0]?.options[0]?.label).toBe('manualna')
  })
})
