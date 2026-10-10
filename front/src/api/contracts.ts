export type ApiMeta = {
  request_id: string
}

export type ApiNotification = {
  type: 'success' | 'error' | 'info' | 'warning'
  message: string
}

export type ApiFieldErrors = Record<string, string[]>

export type ApiSuccessResponse<T> = {
  data: T
  meta: ApiMeta
  notification?: ApiNotification
}

export type ApiErrorResponse = {
  message: string
  code: string
  errors: ApiFieldErrors
  meta: ApiMeta
}
