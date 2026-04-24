// src/api/client.ts
import axios from 'axios'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim() || '/api'
axios.defaults.baseURL = apiBaseUrl

const sessionToken = localStorage.getItem('tcx-session-token')
const username = localStorage.getItem('tcx-username')
if (sessionToken) {
  axios.defaults.headers.common['x-session-token'] = sessionToken
}
if (username) {
  axios.defaults.headers.common['x-username'] = username
}

axios.interceptors.request.use((config) => {
  const token = localStorage.getItem('tcx-session-token')
  const user = localStorage.getItem('tcx-username')

  config.headers = config.headers ?? {}

  if (token) {
    ;(config.headers as Record<string, string>)['x-session-token'] = token
  } else {
    delete (config.headers as Record<string, string>)['x-session-token']
  }

  if (user) {
    ;(config.headers as Record<string, string>)['x-username'] = user
  } else {
    delete (config.headers as Record<string, string>)['x-username']
  }

  return config
})

axios.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status as number | undefined
    const message = error?.response?.data?.message as string | undefined
    const hasSession = Boolean(localStorage.getItem('tcx-session-token'))
    const shouldForceLogout =
      status === 401 ||
      status === 403 ||
      message === 'INVALID_SESSION' ||
      message === 'SESSION_EXPIRED' ||
      message === 'MISSING_SESSION_HEADERS' ||
      message === 'SESSION_USER_MISMATCH'

    if (shouldForceLogout && hasSession) {
      localStorage.removeItem('tcx-session-token')
      localStorage.removeItem('tcx-username')
      delete axios.defaults.headers.common['x-session-token']
      delete axios.defaults.headers.common['x-username']
      window.dispatchEvent(new CustomEvent('tcx-auth-logout'))
    }

    return Promise.reject(error)
  },
)

const client = axios

export { client }
export default client
