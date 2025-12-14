import axios from 'axios'

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
})

client.interceptors.response.use(
  (res) => res,
  (err) => {
    const status = err?.response?.status
    const msg = err?.response?.data?.message

    if (status === 401 && (msg === 'SESSION_EXPIRED' || msg === 'INVALID_SESSION')) {
      localStorage.removeItem('tcx-session-token')
      localStorage.removeItem('tcx-username')
      // szybkie i brutalne, ale skuteczne na MVP:
      window.location.reload()
    }

    return Promise.reject(err)
  },
)

export default client
