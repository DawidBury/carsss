import type { Drive, Gearbox, Variant } from '../api/types'
import { formatVariantDiscriminator } from '../format/labels'

export type VariantOption = {
  variantId: string
  label: string
  variant: Variant
}

export type VersionGroup = {
  versionId: string
  versionName: string
  options: VariantOption[]
}

const gearboxOrder: readonly (Gearbox | null)[] = ['manual', 'automatic', null]
const driveOrder: readonly (Drive | null)[] = ['front', 'rear', 'all', null]

function rank<T>(order: readonly T[], value: T): number {
  const index = order.indexOf(value)
  return index === -1 ? order.length : index
}

function compareOptions(a: Variant, b: Variant): number {
  const gearboxDelta = rank(gearboxOrder, a.gearbox) - rank(gearboxOrder, b.gearbox)
  if (gearboxDelta !== 0) return gearboxDelta
  return rank(driveOrder, a.drive) - rank(driveOrder, b.drive)
}

const versionNameCollator = new Intl.Collator('pl', { numeric: true })

export function groupVariantsByVersion(
  variants: readonly Variant[],
): VersionGroup[] {
  const groupsByVersionId = new Map<string, VersionGroup>()

  for (const variant of variants) {
    const { version } = variant
    let group = groupsByVersionId.get(version.id)
    if (group === undefined) {
      group = { versionId: version.id, versionName: version.name, options: [] }
      groupsByVersionId.set(version.id, group)
    }
    group.options.push({
      variantId: variant.id,
      label: formatVariantDiscriminator(variant.gearbox, variant.drive),
      variant,
    })
  }

  const groups = [...groupsByVersionId.values()]
  for (const group of groups) {
    group.options.sort((a, b) => compareOptions(a.variant, b.variant))
  }
  groups.sort((a, b) => versionNameCollator.compare(a.versionName, b.versionName))

  return groups
}
