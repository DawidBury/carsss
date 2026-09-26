import { ref } from 'vue'
import type { Ref } from 'vue'

export const VARIANT_URL_PARAM = 'v'

export type VariantIdUrl = {
  variantIdFromUrl: Readonly<Ref<string | null>>
  pushVariantId: (variantId: string | null) => void
}

const uuidPattern =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

export function readVariantIdFromSearch(search: string): string | null {
  const value = new URLSearchParams(search).get(VARIANT_URL_PARAM)
  if (value === null || !uuidPattern.test(value)) return null
  return value
}

function buildPath(variantId: string | null): string {
  const params = new URLSearchParams(window.location.search)
  if (variantId === null) {
    params.delete(VARIANT_URL_PARAM)
  } else {
    params.set(VARIANT_URL_PARAM, variantId)
  }
  const query = params.toString()
  return `${window.location.pathname}${query ? `?${query}` : ''}`
}

export function useVariantIdInUrl(): VariantIdUrl {
  const variantIdFromUrl = ref<string | null>(
    readVariantIdFromSearch(window.location.search),
  )

  window.addEventListener('popstate', () => {
    variantIdFromUrl.value = readVariantIdFromSearch(window.location.search)
  })

  function pushVariantId(variantId: string | null): void {
    if (variantId === variantIdFromUrl.value) return
    window.history.pushState(null, '', buildPath(variantId))
    variantIdFromUrl.value = variantId
  }

  return { variantIdFromUrl, pushVariantId }
}
