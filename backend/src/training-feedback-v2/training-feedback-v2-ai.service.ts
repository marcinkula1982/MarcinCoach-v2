import { Injectable, NotFoundException, InternalServerErrorException } from '@nestjs/common'
import { PrismaService } from '../prisma.service'
import { AiCacheService } from '../ai-cache/ai-cache.service'
import type { TrainingFeedbackV2 } from './training-feedback-v2.types'
import { normalizeLegacyFeedback } from './training-feedback-v2-normalize'
import { createHash } from 'crypto'

const OpenAI = require('openai')

@Injectable()
export class TrainingFeedbackV2AiService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly aiCacheService: AiCacheService,
  ) {}

  private safeJsonParse<T = any>(val: any): T | null {
    if (val == null) return null
    if (typeof val === 'string') {
      try {
        return JSON.parse(val) as T
      } catch {
        return null
      }
    }
    return val as T
  }

  private extractResponseOutputText(response: any): string | undefined {
    const direct = response?.output_text
    if (typeof direct === 'string' && direct.trim().length > 0) return direct

    const out = response?.output
    if (!Array.isArray(out)) return undefined

    for (const item of out) {
      const content = item?.content
      if (!Array.isArray(content)) continue

      for (const c of content) {
        if (c?.type === 'output_text' || c?.type === 'text') {
          if (typeof c?.text === 'string' && c.text.trim().length > 0) return c.text
          if (typeof c?.text?.value === 'string' && c.text.value.trim().length > 0) return c.text.value
        }

        // Fallback: even without type, accept plain string text
        if (typeof c?.text === 'string' && c.text.trim().length > 0) return c.text
      }
    }

    return undefined
  }

  private buildStubAnswer(feedback: TrainingFeedbackV2, question: string): string {
    const normalizedQuestion = question.toLowerCase().trim()
    
    // Proste odpowiedzi na podstawie słów kluczowych w pytaniu
    if (normalizedQuestion.includes('economy') || normalizedQuestion.includes('ekonomia')) {
      const paceEquality = feedback.metrics?.paceEquality ?? 0
      if (paceEquality > 0.8) {
        return 'Ekonomia biegu w tym treningu była dobra. Tempo było stabilne, co wskazuje na efektywne wykorzystanie energii.'
      } else {
        return 'Ekonomia biegu w tym treningu wymaga poprawy. Tempo było niestabilne, co może wskazywać na zmęczenie lub nieoptymalną technikę.'
      }
    }
    
    if (normalizedQuestion.includes('hr') || normalizedQuestion.includes('tętno') || normalizedQuestion.includes('puls')) {
      const hrStable = feedback.coachSignals?.hrStable ?? false
      if (hrStable) {
        return 'Tętno w tym treningu było stabilne, co wskazuje na dobre zarządzanie intensywnością.'
      } else {
        return 'Tętno w tym treningu wykazywało niestabilność, co może wskazywać na zmęczenie lub zbyt wysoką intensywność.'
      }
    }
    
    if (normalizedQuestion.includes('load') || normalizedQuestion.includes('obciążenie') || normalizedQuestion.includes('obciazenie')) {
      const loadHeavy = feedback.coachSignals?.loadHeavy ?? false
      if (loadHeavy) {
        return 'To trening o wysokim obciążeniu. Zadbaj o odpowiednią regenerację przed kolejnym intensywnym treningiem.'
      } else {
        return 'Obciążenie tego treningu było umiarkowane, co pozwala na szybszą regenerację.'
      }
    }
    
    if (normalizedQuestion.includes('character') || normalizedQuestion.includes('charakter') || normalizedQuestion.includes('typ')) {
      const character = feedback.coachSignals?.character ?? 'easy'
      const characterMap: Record<string, string> = {
        easy: 'Trening o charakterze regeneracyjnym - spokojny, w komforcie oddechowym.',
        tempo: 'Trening tempowy - umiarkowana intensywność, kontrolowane tempo.',
        interval: 'Trening interwałowy - wysokie obciążenie, wymaga regeneracji.',
        regeneration: 'Trening regeneracyjny - niska intensywność, wspomaga odnowę.',
      }
      return characterMap[character] || 'Charakter treningu został określony na podstawie analizy intensywności i tempa.'
    }
    
    // Domyślna odpowiedź stub
    return `Na podstawie analizy treningu: charakter "${feedback.coachSignals?.character || 'nieokreślony'}", ${feedback.coachSignals?.hrStable ? 'stabilne' : 'niestabilne'} tętno, ${feedback.coachSignals?.economyGood ? 'dobra' : 'wymagająca poprawy'} ekonomia biegu.`
  }

  async answerQuestion(
    feedbackId: number,
    userId: number,
    question: string,
  ): Promise<{ answer: string; cache: 'hit' | 'miss' }> {
    // Pobierz feedback z DB
    const record = await (this.prisma as any).trainingFeedbackV2.findFirst({
      where: {
        id: feedbackId,
        userId,
      },
    })

    if (!record) {
      throw new NotFoundException('Feedback not found')
    }

    const feedbackRaw = this.safeJsonParse<any>(record.feedback)
    if (!feedbackRaw) {
      throw new NotFoundException('Feedback data not found')
    }

    // TODO: Remove after V1 → V2 data migration
    // This normalization handles old snapshot formats
    const feedback = normalizeLegacyFeedback(feedbackRaw)
    if (!feedback) {
      throw new NotFoundException('Feedback data normalization failed')
    }

    // Normalizuj pytanie przed hashem
    const normalizedQuestion = question.trim().replace(/\s+/g, ' ')
    const questionHash = createHash('sha256').update(normalizedQuestion).digest('hex').slice(0, 24)

    // Cache: per userId + day, hit tylko gdy pasuje questionHash + feedbackId
    // Ograniczenie: jeden wpis dziennie na usera (nadpisywanie przy innym pytaniu/feedbacku tego samego dnia)
    const cached = this.aiCacheService.get<{ answer: string; questionHash: string; feedbackId: number }>('feedback', userId, 1)
    let cacheStatus: 'hit' | 'miss' = 'miss'
    
    // Sprawdź czy cache pasuje do pytania i feedbackId (proste sprawdzenie hash)
    if (cached && cached.payload.questionHash === questionHash && cached.payload.feedbackId === feedbackId) {
      cacheStatus = 'hit'
      return { answer: cached.payload.answer, cache: cacheStatus }
    }

    // Wywołaj OpenAI lub użyj stub (z cache per dzień + rate limit)
    const shouldUseOpenAi = process.env.AI_FEEDBACK_PROVIDER === 'openai'
    const apiKeyOk = Boolean(process.env.OPENAI_API_KEY && process.env.OPENAI_API_KEY.trim().length > 0)

    if (shouldUseOpenAi && !apiKeyOk) {
      throw new InternalServerErrorException('OPENAI_API_KEY missing')
    }

    let answer: string
    if (shouldUseOpenAi) {
      try {
        // Buduj prompt z kontekstem feedbacku + pytaniem
        const context = JSON.stringify(feedback, null, 2)
        const instructions = 'Jesteś asystentem trenera biegowego. Odpowiadaj krótko i konkretnie po polsku.'
        const input = `Oto feedback z treningu:\n\n${context}\n\nPytanie użytkownika: ${normalizedQuestion}\n\nOdpowiedz krótko i konkretnie po polsku.`

        const client = new OpenAI({ apiKey: process.env.OPENAI_API_KEY })
        const model = process.env.OPENAI_MODEL || 'gpt-4o-mini'
        const maxOut = Number(process.env.OPENAI_MAX_OUTPUT_TOKENS || 220)

        const response: any = await client.responses.create({
          model,
          instructions,
          input,
          max_output_tokens: maxOut,
          temperature: 0,
        })

        const outputText = this.extractResponseOutputText(response)
        answer = outputText?.trim() || 'Nie udało się wygenerować odpowiedzi.'
      } catch (err: any) {
        throw new InternalServerErrorException(`OpenAI call failed: ${err.message}`)
      }
    } else {
      // Fallback na stub
      answer = this.buildStubAnswer(feedback, normalizedQuestion)
    }

    // Zapisz w cache
    this.aiCacheService.set('feedback', userId, 1, { answer, questionHash, feedbackId })

    return { answer, cache: cacheStatus }
  }
}

