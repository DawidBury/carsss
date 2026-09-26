import { MAX_PAGE_LIMIT, getVariantsPage } from '../api/client'
import type { RequestOptions } from '../api/client'
import type { Variant } from '../api/types'

export type ModelVariants = {
  variants: Variant[]
  truncated: boolean
}

export const MAX_VARIANT_PAGES = 5

export async function fetchModelVariants(
  modelId: string,
  options?: RequestOptions,
): Promise<ModelVariants> {
  const variants: Variant[] = []
  let cursor: string | undefined
  let pageCount = 0

  while (pageCount < MAX_VARIANT_PAGES) {
    const page = await getVariantsPage(
      { modelId, limit: MAX_PAGE_LIMIT, cursor },
      options,
    )
    variants.push(...page.data)
    pageCount += 1

    if (page.page.next === null) {
      return { variants, truncated: false }
    }

    cursor = page.page.next
  }

  return { variants, truncated: true }
}
