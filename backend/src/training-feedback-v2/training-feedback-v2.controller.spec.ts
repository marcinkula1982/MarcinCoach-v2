import { NotFoundException } from '@nestjs/common'
import { TrainingFeedbackV2Controller } from './training-feedback-v2.controller'
import { TrainingFeedbackV2Service } from './training-feedback-v2.service'
import { TrainingFeedbackV2AiService } from './training-feedback-v2-ai.service'
import type { TrainingFeedbackV2 } from './training-feedback-v2.types'

describe('TrainingFeedbackV2Controller - getSignals', () => {
  let controller: TrainingFeedbackV2Controller
  let mockService: { getFeedbackForWorkout: jest.Mock; generateFeedback: jest.Mock }

  const mockFeedback: TrainingFeedbackV2 = {
    character: 'easy',
    hrStability: { drift: null, artefacts: false },
    economy: { paceEquality: 0.85, variance: 0.1 },
    loadImpact: { weeklyLoadContribution: 30, intensityScore: 100 },
    coachSignals: {
      character: 'easy',
      hrStable: true,
      economyGood: true,
      loadHeavy: false,
    },
    metrics: {
      hrDrift: null,
      paceEquality: 0.85,
      weeklyLoadContribution: 30,
    },
    workoutId: 1,
  }

  beforeEach(() => {
    mockService = {
      getFeedbackForWorkout: jest.fn(),
      generateFeedback: jest.fn(),
    }

    const mockAiService = {
      answerQuestion: jest.fn(),
    }

    controller = new TrainingFeedbackV2Controller(mockService as any, mockAiService as any)
  })

  describe('getSignals', () => {
    it('should return FeedbackSignals when feedback exists', async () => {
      const workoutId = 1
      const userId = 1
      const createdAt = new Date()

      mockService.getFeedbackForWorkout.mockResolvedValue({
        id: 1,
        feedback: mockFeedback,
        createdAt,
      })

      const req = {
        authUser: { userId },
      } as any

      const result = await controller.getSignals(workoutId, req)

      expect(mockService.getFeedbackForWorkout).toHaveBeenCalledWith(workoutId, userId)
      expect(result).toEqual({
        intensityClass: 'easy',
        hrStable: true,
        economyFlag: 'good',
        loadImpact: 'medium',
        warnings: {},
      })
    })

    it('should throw NotFoundException when feedback does not exist', async () => {
      const workoutId = 1
      const userId = 1

      mockService.getFeedbackForWorkout.mockResolvedValue(null)

      const req = {
        authUser: { userId },
      } as any

      await expect(controller.getSignals(workoutId, req)).rejects.toThrow(NotFoundException)
      expect(mockService.getFeedbackForWorkout).toHaveBeenCalledWith(workoutId, userId)
    })
  })
})

