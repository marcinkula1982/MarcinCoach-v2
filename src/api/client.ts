// src/api/client.ts
import axios from 'axios'

export const SESSION_TOKEN_KEY = 'marcincoach-session-token'
export const SESSION_USERNAME_KEY = 'marcincoach-username'
export const AUTH_LOGOUT_EVENT = 'marcincoach-auth-logout'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim() || '/api'
axios.defaults.baseURL = apiBaseUrl

const LEGACY_SESSION_PREFIX = 'tcx'
const LEGACY_SESSION_TOKEN_KEY = [LEGACY_SESSION_PREFIX, 'session', 'token'].join('-')
const LEGACY_SESSION_USERNAME_KEY = [LEGACY_SESSION_PREFIX, 'username'].join('-')

function migrateLegacySession() {
  const legacyToken = localStorage.getItem(LEGACY_SESSION_TOKEN_KEY)
  const legacyUsername = localStorage.getItem(LEGACY_SESSION_USERNAME_KEY)

  if (!localStorage.getItem(SESSION_TOKEN_KEY) && legacyToken) {
    localStorage.setItem(SESSION_TOKEN_KEY, legacyToken)
  }
  if (!localStorage.getItem(SESSION_USERNAME_KEY) && legacyUsername) {
    localStorage.setItem(SESSION_USERNAME_KEY, legacyUsername)
  }

  localStorage.removeItem(LEGACY_SESSION_TOKEN_KEY)
  localStorage.removeItem(LEGACY_SESSION_USERNAME_KEY)
}

migrateLegacySession()

export function getStoredSessionToken() {
  return localStorage.getItem(SESSION_TOKEN_KEY)
}

export function getStoredUsername() {
  return localStorage.getItem(SESSION_USERNAME_KEY)
}

export function hasStoredSession() {
  return Boolean(getStoredSessionToken() && getStoredUsername())
}

export function buildAuthHeaders(): Record<string, string> {
  const sessionToken = getStoredSessionToken()
  const username = getStoredUsername()

  const headers: Record<string, string> = {}
  if (sessionToken) headers['x-session-token'] = sessionToken
  if (username) headers['x-username'] = username
  return headers
}

const _token = getStoredSessionToken()
const _user = getStoredUsername()
if (_token) axios.defaults.headers.common['x-session-token'] = _token
if (_user) axios.defaults.headers.common['x-username'] = _user

export function setSessionHeaders(token: string, username: string) {
  axios.defaults.headers.common['x-session-token'] = token
  axios.defaults.headers.common['x-username'] = username
  localStorage.setItem(SESSION_TOKEN_KEY, token)
  localStorage.setItem(SESSION_USERNAME_KEY, username)
  localStorage.removeItem(LEGACY_SESSION_TOKEN_KEY)
  localStorage.removeItem(LEGACY_SESSION_USERNAME_KEY)
}

export function clearSessionHeaders() {
  delete axios.defaults.headers.common['x-session-token']
  delete axios.defaults.headers.common['x-username']
  localStorage.removeItem(SESSION_TOKEN_KEY)
  localStorage.removeItem(SESSION_USERNAME_KEY)
  localStorage.removeItem(LEGACY_SESSION_TOKEN_KEY)
  localStorage.removeItem(LEGACY_SESSION_USERNAME_KEY)
}

axios.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status as number | undefined
    const message = error?.response?.data?.message as string | undefined
    const hasSession = hasStoredSession()

    const isAutoLoadEndpoint =
      error?.config?.url?.includes('weekly-plan') ||
      error?.config?.url?.includes('rolling-plan') ||
      error?.config?.url?.includes('workouts') ||
      error?.config?.url?.includes('training-signals') ||
      error?.config?.url?.includes('training-analysis') ||
      error?.config?.url?.includes('onboarding-summary') ||
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
      window.dispatchEvent(new CustomEvent(AUTH_LOGOUT_EVENT))
    }

    return Promise.reject(error)
  },
)

const client = axios

export { client }
export default client
