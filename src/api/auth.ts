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

export const register = async (
  username: string,
  password: string,
  email?: string,
): Promise<LoginResponse> => {
  const response = await client.post<LoginResponse>('/auth/register', {
    username,
    ...(email ? { email } : {}),
    password,
  })
  const { sessionToken, username: registeredUsername } = response.data
  setSessionHeaders(sessionToken, registeredUsername)
  return response.data
}

export const forgotPassword = async (identifier: string): Promise<{ ok: boolean }> => {
  const response = await client.post<{ ok: boolean }>('/auth/forgot-password', {
    identifier,
  })
  return response.data
}

export const resetPassword = async (
  identifier: string,
  token: string,
  password: string,
): Promise<{ ok: boolean }> => {
  const response = await client.post<{ ok: boolean }>('/auth/reset-password', {
    identifier,
    token,
    password,
  })
  return response.data
}
