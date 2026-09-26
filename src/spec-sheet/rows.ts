import type { Variant, Version } from '../api/types'
import {
  formatDrive,
  formatFuelType,
  formatGearbox,
  formatVariantDiscriminator,
  specSheetGroupLabels,
  specSheetRowLabels,
} from '../format/labels'
import {
  UNKNOWN_VALUE,
  formatAcceleration,
  formatConsumption,
  formatDisplacement,
  formatLitres,
  formatPower,
} from '../format/numbers'

export type SpecRow = {
  label: string
  value: string
  isUnknown: boolean
}

export type SpecGroup = {
  label: string
  rows: SpecRow[]
}

export type SpecSheetHeader = {
  kicker: string
  title: string
  meta: string
}

function row(label: string, value: string): SpecRow {
  return { label, value, isUnknown: value === UNKNOWN_VALUE }
}

export function buildSpecGroups(variant: Variant): SpecGroup[] {
  const { version } = variant

  return [
    {
      label: specSheetGroupLabels.engine,
      rows: [
        row(
          specSheetRowLabels.displacement,
          formatDisplacement(version.engineDisplacementCcm),
        ),
        row(specSheetRowLabels.power, formatPower(version.powerHp)),
        row(specSheetRowLabels.fuelType, formatFuelType(version.fuelType)),
      ],
    },
    {
      label: specSheetGroupLabels.drivetrain,
      rows: [
        row(specSheetRowLabels.gearbox, formatGearbox(variant.gearbox)),
        row(specSheetRowLabels.drive, formatDrive(variant.drive)),
      ],
    },
    {
      label: specSheetGroupLabels.performance,
      rows: [
        row(
          specSheetRowLabels.acceleration,
          formatAcceleration(variant.acceleration0To100S),
        ),
        row(
          specSheetRowLabels.consumption,
          formatConsumption(variant.fuelConsumptionL100Km),
        ),
        row(specSheetRowLabels.fuelTank, formatLitres(variant.fuelTankL)),
        row(
          specSheetRowLabels.bootCapacity,
          formatLitres(version.bootCapacityL),
        ),
      ],
    },
  ]
}

function buildVersionSegments(version: Version): string[] {
  if (version.generation !== null && version.bodyStyle !== null) {
    return [version.generation, version.bodyStyle]
  }
  if (version.unresolvedSegment !== null) {
    return [version.unresolvedSegment]
  }
  return []
}

export function buildSpecSheetHeader(variant: Variant): SpecSheetHeader {
  const { version } = variant
  const discriminator = formatVariantDiscriminator(variant.gearbox, variant.drive)
  const segments = [
    ...buildVersionSegments(version),
    ...(discriminator === '' ? [] : [discriminator]),
  ]

  return {
    kicker: `${version.model.brand.name} · ${version.model.name}`,
    title: version.name,
    meta: segments.join(' · '),
  }
}

export function buildShareUrl(variantId: string): string {
  return `${window.location.origin}${window.location.pathname}?v=${variantId}`
}
