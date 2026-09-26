import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
  VARIANT_URL_PARAM,
  readVariantIdFromSearch,
  useVariantIdInUrl,
} from './url'

const validId = '2dcfd5b1-a5d7-442c-ad3d-28fe31b28483'
const otherId = 'da40e909-52d9-499c-aa1d-eb3f78472d2d'

beforeEach(() => {
  window.history.replaceState(null, '', '/')
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe('readVariantIdFromSearch', () => {
  it('returns null when the param is absent', () => {
    expect(readVariantIdFromSearch('')).toBeNull()
  })

  it('returns null for a value that is not a UUID', () => {
    expect(readVariantIdFromSearch(`?${VARIANT_URL_PARAM}=not-a-uuid`)).toBeNull()
  })

  it('returns the id for a well-formed UUID', () => {
    expect(readVariantIdFromSearch(`?${VARIANT_URL_PARAM}=${validId}`)).toBe(
      validId,
    )
  })
})

describe('useVariantIdInUrl', () => {
  it('reads the id present on the URL at call time', () => {
    window.history.replaceState(null, '', `/?${VARIANT_URL_PARAM}=${validId}`)

    const { variantIdFromUrl } = useVariantIdInUrl()

    expect(variantIdFromUrl.value).toBe(validId)
  })

  it('starts with null when the URL carries no variant', () => {
    const { variantIdFromUrl } = useVariantIdInUrl()

    expect(variantIdFromUrl.value).toBeNull()
  })

  it('pushes the id into the URL and updates the ref', () => {
    const { variantIdFromUrl, pushVariantId } = useVariantIdInUrl()

    pushVariantId(validId)

    expect(variantIdFromUrl.value).toBe(validId)
    expect(window.location.search).toBe(`?${VARIANT_URL_PARAM}=${validId}`)
  })

  it('writes the bare path when clearing the variant', () => {
    window.history.replaceState(null, '', `/?${VARIANT_URL_PARAM}=${validId}`)
    const { pushVariantId } = useVariantIdInUrl()

    pushVariantId(null)

    expect(window.location.search).toBe('')
    expect(window.location.pathname).toBe('/')
  })

  it('does not push a new entry when the id is unchanged', () => {
    const { pushVariantId } = useVariantIdInUrl()
    pushVariantId(validId)
    const lengthAfterFirstPush = window.history.length

    pushVariantId(validId)

    expect(window.history.length).toBe(lengthAfterFirstPush)
  })

  it('updates the ref on popstate without pushing a new entry', () => {
    const { variantIdFromUrl, pushVariantId } = useVariantIdInUrl()
    pushVariantId(validId)
    const lengthAfterPush = window.history.length

    window.history.replaceState(null, '', `/?${VARIANT_URL_PARAM}=${otherId}`)
    window.dispatchEvent(new PopStateEvent('popstate'))

    expect(variantIdFromUrl.value).toBe(otherId)
    expect(window.history.length).toBe(lengthAfterPush)
  })

  it('treats a popstate back to a bare path as clearing the variant', () => {
    const { variantIdFromUrl, pushVariantId } = useVariantIdInUrl()
    pushVariantId(validId)

    window.history.replaceState(null, '', '/')
    window.dispatchEvent(new PopStateEvent('popstate'))

    expect(variantIdFromUrl.value).toBeNull()
  })
})
