import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getVariantsPage } from '../api/client'
import rawVariantFixture from '../api/fixtures/variant.json'
import type { Variant } from '../api/types'
import { MAX_VARIANT_PAGES, fetchModelVariants } from './paging'

const variantFixture = rawVariantFixture as Variant

vi.mock('../api/client', () => ({
  MAX_PAGE_LIMIT: 200,
  getVariantsPage: vi.fn(),
}))

const getVariantsPageMock = vi.mocked(getVariantsPage)

function makeVariant(id: string): Variant {
  return { ...variantFixture, id }
}

beforeEach(() => {
  getVariantsPageMock.mockReset()
})

describe('fetchModelVariants', () => {
  it('returns a single page untruncated when there is no next cursor', async () => {
    getVariantsPageMock.mockResolvedValueOnce({
      data: [makeVariant('a'), makeVariant('b')],
      page: { limit: 200, next: null },
    })

    const result = await fetchModelVariants('model-1')

    expect(result.truncated).toBe(false)
    expect(result.variants.map((v) => v.id)).toEqual(['a', 'b'])
    expect(getVariantsPageMock).toHaveBeenCalledTimes(1)
    expect(getVariantsPageMock).toHaveBeenCalledWith(
      { modelId: 'model-1', limit: 200, cursor: undefined },
      undefined,
    )
  })

  it('follows the cursor across pages and concatenates the data', async () => {
    getVariantsPageMock
      .mockResolvedValueOnce({
        data: [makeVariant('a')],
        page: { limit: 200, next: 'cursor-1' },
      })
      .mockResolvedValueOnce({
        data: [makeVariant('b')],
        page: { limit: 200, next: null },
      })

    const result = await fetchModelVariants('model-1')

    expect(result.truncated).toBe(false)
    expect(result.variants.map((v) => v.id)).toEqual(['a', 'b'])
    expect(getVariantsPageMock).toHaveBeenCalledTimes(2)
    expect(getVariantsPageMock).toHaveBeenNthCalledWith(
      2,
      { modelId: 'model-1', limit: 200, cursor: 'cursor-1' },
      undefined,
    )
  })

  it('re-sends the identical filter set with the cursor on every page', async () => {
    getVariantsPageMock
      .mockResolvedValueOnce({
        data: [makeVariant('a')],
        page: { limit: 200, next: 'cursor-1' },
      })
      .mockResolvedValueOnce({
        data: [makeVariant('b')],
        page: { limit: 200, next: null },
      })

    await fetchModelVariants('model-1')

    for (const call of getVariantsPageMock.mock.calls) {
      expect(call[0].modelId).toBe('model-1')
      expect(call[0].limit).toBe(200)
    }
  })

  it('stops at the bound and reports truncated when a version has one variant per page', async () => {
    for (let page = 0; page < MAX_VARIANT_PAGES; page += 1) {
      getVariantsPageMock.mockResolvedValueOnce({
        data: [makeVariant(`v${page}`)],
        page: { limit: 200, next: `cursor-${page}` },
      })
    }

    const result = await fetchModelVariants('model-1')

    expect(result.truncated).toBe(true)
    expect(result.variants).toHaveLength(MAX_VARIANT_PAGES)
    expect(getVariantsPageMock).toHaveBeenCalledTimes(MAX_VARIANT_PAGES)
  })

  it('is not truncated when the last allowed page has no further cursor', async () => {
    for (let page = 0; page < MAX_VARIANT_PAGES - 1; page += 1) {
      getVariantsPageMock.mockResolvedValueOnce({
        data: [makeVariant(`v${page}`)],
        page: { limit: 200, next: `cursor-${page}` },
      })
    }
    getVariantsPageMock.mockResolvedValueOnce({
      data: [makeVariant('last')],
      page: { limit: 200, next: null },
    })

    const result = await fetchModelVariants('model-1')

    expect(result.truncated).toBe(false)
    expect(result.variants).toHaveLength(MAX_VARIANT_PAGES)
    expect(getVariantsPageMock).toHaveBeenCalledTimes(MAX_VARIANT_PAGES)
  })

  it('forwards the request options to every page request', async () => {
    getVariantsPageMock.mockResolvedValueOnce({
      data: [],
      page: { limit: 200, next: null },
    })
    const controller = new AbortController()

    await fetchModelVariants('model-1', { signal: controller.signal })

    expect(getVariantsPageMock).toHaveBeenCalledWith(
      { modelId: 'model-1', limit: 200, cursor: undefined },
      { signal: controller.signal },
    )
  })
})
