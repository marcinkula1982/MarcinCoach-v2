import client from './client'

export type UserProfile = {
  id: number
  userId: number
  preferredRunDays: string | null
  preferredSurface: string | null
  goals: string | null
  constraints: string | null
  createdAt: string
  updatedAt: string
}

export type UpdateProfilePayload = {
  preferredRunDays?: string
  preferredSurface?: string
  goals?: string
  constraints?: string
}

export async function getMyProfile(): Promise<UserProfile> {
  const res = await client.get<UserProfile>('/me/profile')
  return res.data
}

export async function updateMyProfile(
  payload: UpdateProfilePayload,
): Promise<UserProfile> {
  const res = await client.put<UserProfile>('/me/profile', payload)
  return res.data
}


