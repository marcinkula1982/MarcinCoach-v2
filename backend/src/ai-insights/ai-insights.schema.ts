import { z } from 'zod'
import type { AiInsights } from './ai-insights.types'

export const aiInsightsSchema = z.object({
  generatedAtIso: z.string().datetime(),
  windowDays: z.number(),
  summary: z.array(z.string()).max(5),
  risks: z.array(z.enum(['fatigue', 'inconsistency', 'low-compliance', 'none'])),
  questions: z.array(z.string()).max(3),
  confidence: z.number().min(0).max(1),
}) as z.ZodType<AiInsights>


