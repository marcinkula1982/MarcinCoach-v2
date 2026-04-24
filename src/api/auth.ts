import client from './client'
import { setSessionHeaders } from './client'

export interface LoginResponse {
  sessionToken: string
  username: string
}

export const login = async (
  username: string,
  password: string,
): Promise<LoginResponse> => {
  const response = await client.post<LoginResponse>('/auth/login', {
    username,
    password,
  })
  const { sessionToken, username: loggedUsername } = response.data
  setSessionHeaders(sessionToken, loggedUsername)
  return response.data
}
