import type { ProblemDetails } from './types'

export type ApiErrorKind =
  | 'network'
  | 'invalid-request'
  | 'unauthorized'
  | 'not-found'
  | 'server'
  | 'unexpected'

export class ApiError extends Error {
  readonly kind: ApiErrorKind
  readonly status: number | null
  readonly problem: ProblemDetails | null
  readonly traceId: string | null

  constructor(
    kind: ApiErrorKind,
    status: number | null,
    problem: ProblemDetails | null,
  ) {
    super(problem?.detail ?? problem?.title ?? kind)
    this.name = 'ApiError'
    this.kind = kind
    this.status = status
    this.problem = problem
    this.traceId = problem?.traceId ?? null
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

export function toApiError(error: unknown): ApiError {
  if (isApiError(error)) return error
  return new ApiError('network', null, null)
}

export function shouldRetryRequest(
  failureCount: number,
  error: unknown,
): boolean {
  if (failureCount >= 2) return false

  const kind = toApiError(error).kind
  return kind === 'server' || kind === 'network'
}
