// src/api/client.ts
import axios from 'axios'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim() || '/api'
axios.defaults.baseURL = apiBaseUrl

// Przywróć sesję z localStorage przy starcie (np. po F5)
const _token = localStorage.getItem('tcx-session-token')
const _user = localStorage.getItem('tcx-username')
if (_token) axios.defaults.headers.common['x-session-token'] = _token
if (_user) axios.defaults.headers.common['x-username'] = _user

// Eksportowane funkcje do zarządzania sesją (używane w auth.ts i App.tsx)
export function setSessionHeaders(token: string, username: string) {
  axios.defaults.headers.common['x-session-token'] = token
  axios.defaults.headers.common['x-username'] = username
  localStorage.setItem('tcx-session-token', token)
  localStorage.setItem('tcx-username', username)
}

export function clearSessionHeaders() {
  delete axios.defaults.headers.common['x-session-token']
  delete axios.defaults.headers.common['x-username']
  localStorage.removeItem('tcx-session-token')
  localStorage.removeItem('tcx-username')
}

axios.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status as number | undefined
    const message = error?.response?.data?.message as string | undefined
    const hasSession = Boolean(localStorage.getItem('tcx-session-token'))

    const isAutoLoadEndpoint =
      error?.config?.url?.includes('weekly-plan') ||
      error?.config?.url?.includes('workouts') ||
      error?.config?.url?.includes('training-signals') ||
      error?.config?.url?.includes('ai/plan') ||
      error?.config?.url?.includes('summary') ||
      error?.config?.url?.includes('me/profile')

    const shouldForceLogout =
      status === 401 ||
      status === 403 ||
      message === 'INVALID_SESSION' ||
      message === 'SESSION_EXPIRED' ||
      message === 'MISSING_SESSION_HEADERS' ||
      message === 'SESSION_USER_MISMATCH'

    if (shouldForceLogout && hasSession && !isAutoLoadEndpoint) {
      clearSessionHeaders()
      window.dispatchEvent(new CustomEvent('tcx-auth-logout'))
    }

    return Promise.reject(error)
  },
)

const client = axios

export { client }
export default client