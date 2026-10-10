import { describe, expect, it } from 'vitest'
import { t } from './fr'

describe('traductions françaises', () => {
  it('remplace toutes les variables d un message', () => {
    expect(
      t('system.operational', {
        name: 'UCG Platform',
        version: 'v1',
      }),
    ).toBe('UCG Platform v1 opérationnelle')
  })
})
