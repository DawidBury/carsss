import { describe, expect, it } from 'vitest'
import type { ProblemDetails } from './types'
import { ApiError, isApiError, shouldRetryRequest, toApiError } from './errors'

const problem: ProblemDetails = {
  type: 'https://api.example.com/problems/not-found',
  title: 'Resource not found',
  status: 404,
  detail: "Variant 'x' was not found.",
}

describe('ApiError', () => {
  it('carries the kind, status and problem it was built from', () => {
    const error = new ApiError('not-found', 404, problem)

    expect(error.kind).toBe('not-found')
    expect(error.status).toBe(404)
    expect(error.problem).toBe(problem)
    expect(error.message).toBe(problem.detail)
  })

  it('reads traceId from the problem body when present', () => {
    const withTrace: ProblemDetails = {
      type: 'https://api.example.com/problems/internal-error',
      title: 'Internal server error',
      status: 500,
      traceId: '01J8Z000',
    }

    expect(new ApiError('server', 500, withTrace).traceId).toBe('01J8Z000')
  })

  it('has a null traceId when there is no problem body', () => {
    expect(new ApiError('network', null, null).traceId).toBeNull()
  })
})

describe('isApiError', () => {
  it('recognises an ApiError instance', () => {
    expect(isApiError(new ApiError('network', null, null))).toBe(true)
  })

  it('rejects anything else', () => {
    expect(isApiError(new Error('plain'))).toBe(false)
    expect(isApiError('not an error')).toBe(false)
    expect(isApiError(null)).toBe(false)
  })
})

describe('toApiError', () => {
  it('passes an existing ApiError through unchanged', () => {
    const error = new ApiError('unauthorized', 401, null)
    expect(toApiError(error)).toBe(error)
  })

  it('wraps a network-level failure as kind network', () => {
    const wrapped = toApiError(new TypeError('Failed to fetch'))
    expect(wrapped.kind).toBe('network')
    expect(wrapped.status).toBeNull()
  })

  it('wraps a non-Error thrown value as kind network', () => {
    expect(toApiError('boom').kind).toBe('network')
  })
})

describe('shouldRetryRequest', () => {
  it('retries a server error twice, then stops', () => {
    const error = new ApiError('server', 500, null)
    expect(shouldRetryRequest(0, error)).toBe(true)
    expect(shouldRetryRequest(1, error)).toBe(true)
    expect(shouldRetryRequest(2, error)).toBe(false)
  })

  it('retries a network failure', () => {
    expect(shouldRetryRequest(0, new ApiError('network', null, null))).toBe(
      true,
    )
  })

  it('never retries a 400', () => {
    expect(
      shouldRetryRequest(0, new ApiError('invalid-request', 400, null)),
    ).toBe(false)
  })

  it('never retries a 401', () => {
    expect(shouldRetryRequest(0, new ApiError('unauthorized', 401, null))).toBe(
      false,
    )
  })

  it('never retries a 404', () => {
    expect(shouldRetryRequest(0, new ApiError('not-found', 404, null))).toBe(
      false,
    )
  })
})
