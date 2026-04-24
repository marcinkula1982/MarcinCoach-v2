import axios from 'axios'
import client from './client'

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
  axios.defaults.headers.common['x-session-token'] = sessionToken
  axios.defaults.headers.common['x-username'] = loggedUsername
  return response.data
}


