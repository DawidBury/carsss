import { nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type { SearchHit } from '../api/types'
import { describeResultCount, useComboboxNavigation } from './navigation'

function makeHit(id: string): SearchHit {
  return {
    id,
    brand: 'Audi',
    model: 'A4',
    version: '1.9 TDI',
    fuelType: 'diesel',
    generation: 'B5',
    bodyStyle: 'Sedan',
    unresolvedSegment: null,
    gearbox: 'manual',
    drive: 'front',
  }
}

function keydown(key: string): KeyboardEvent {
  return new KeyboardEvent('keydown', { key, cancelable: true })
}

function setup(initialHits: SearchHit[] = []) {
  const hits = ref<readonly SearchHit[]>(initialHits)
  const onSelect = vi.fn()
  const onClear = vi.fn()
  const navigation = useComboboxNavigation({ hits, onSelect, onClear })
  return { hits, onSelect, onClear, navigation }
}

describe('useComboboxNavigation', () => {
  describe('ArrowDown', () => {
    it('opens and activates the first option when closed', () => {
      const { navigation } = setup([makeHit('a'), makeHit('b')])

      navigation.handleKeydown(keydown('ArrowDown'))

      expect(navigation.isOpen.value).toBe(true)
      expect(navigation.activeIndex.value).toBe(0)
    })

    it('moves to the next option and wraps to the first when open', () => {
      const { navigation } = setup([makeHit('a'), makeHit('b'), makeHit('c')])
      navigation.handleKeydown(keydown('ArrowDown'))
      navigation.handleKeydown(keydown('ArrowDown'))
      expect(navigation.activeIndex.value).toBe(1)

      navigation.handleKeydown(keydown('ArrowDown'))
      expect(navigation.activeIndex.value).toBe(2)

      navigation.handleKeydown(keydown('ArrowDown'))
      expect(navigation.activeIndex.value).toBe(0)
    })

    it('prevents the default caret movement', () => {
      const { navigation } = setup([makeHit('a')])
      const event = keydown('ArrowDown')

      navigation.handleKeydown(event)

      expect(event.defaultPrevented).toBe(true)
    })
  })

  describe('ArrowUp', () => {
    it('opens and activates the last option when closed', () => {
      const { navigation } = setup([makeHit('a'), makeHit('b'), makeHit('c')])

      navigation.handleKeydown(keydown('ArrowUp'))

      expect(navigation.isOpen.value).toBe(true)
      expect(navigation.activeIndex.value).toBe(2)
    })

    it('moves to the previous option and wraps to the last when open', () => {
      const { navigation } = setup([makeHit('a'), makeHit('b'), makeHit('c')])
      navigation.handleKeydown(keydown('ArrowDown'))
      expect(navigation.activeIndex.value).toBe(0)

      navigation.handleKeydown(keydown('ArrowUp'))

      expect(navigation.activeIndex.value).toBe(2)
    })
  })

  describe('Enter', () => {
    it('does nothing while closed', () => {
      const { navigation, onSelect } = setup([makeHit('a')])

      navigation.handleKeydown(keydown('Enter'))

      expect(onSelect).not.toHaveBeenCalled()
      expect(navigation.isOpen.value).toBe(false)
    })

    it('does nothing when open with no active option', () => {
      const { navigation, onSelect } = setup([makeHit('a')])
      navigation.open()

      navigation.handleKeydown(keydown('Enter'))

      expect(onSelect).not.toHaveBeenCalled()
    })

    it('selects the active option and closes', () => {
      const hitA = makeHit('a')
      const hitB = makeHit('b')
      const { navigation, onSelect } = setup([hitA, hitB])
      navigation.handleKeydown(keydown('ArrowDown'))
      navigation.handleKeydown(keydown('ArrowDown'))

      navigation.handleKeydown(keydown('Enter'))

      expect(onSelect).toHaveBeenCalledWith(hitB)
      expect(navigation.isOpen.value).toBe(false)
      expect(navigation.activeIndex.value).toBe(-1)
    })
  })

  describe('Escape', () => {
    it('closes and keeps the text on the first press while open', () => {
      const { navigation, onClear } = setup([makeHit('a')])
      navigation.handleKeydown(keydown('ArrowDown'))

      navigation.handleKeydown(keydown('Escape'))

      expect(navigation.isOpen.value).toBe(false)
      expect(onClear).not.toHaveBeenCalled()
    })

    it('clears on a press while already closed', () => {
      const { navigation, onClear } = setup([makeHit('a')])

      navigation.handleKeydown(keydown('Escape'))

      expect(onClear).toHaveBeenCalledOnce()
    })

    it('clears on a second consecutive press', () => {
      const { navigation, onClear } = setup([makeHit('a')])
      navigation.handleKeydown(keydown('ArrowDown'))

      navigation.handleKeydown(keydown('Escape'))
      expect(onClear).not.toHaveBeenCalled()

      navigation.handleKeydown(keydown('Escape'))
      expect(onClear).toHaveBeenCalledOnce()
    })
  })

  describe('Tab', () => {
    it('closes without preventing the default focus change', () => {
      const { navigation } = setup([makeHit('a')])
      navigation.handleKeydown(keydown('ArrowDown'))
      const event = keydown('Tab')

      navigation.handleKeydown(event)

      expect(navigation.isOpen.value).toBe(false)
      expect(event.defaultPrevented).toBe(false)
    })
  })

  describe('Home and End', () => {
    it('are left untouched for native caret movement', () => {
      const { navigation } = setup([makeHit('a'), makeHit('b')])
      navigation.handleKeydown(keydown('ArrowDown'))

      const homeEvent = keydown('Home')
      navigation.handleKeydown(homeEvent)
      const endEvent = keydown('End')
      navigation.handleKeydown(endEvent)

      expect(homeEvent.defaultPrevented).toBe(false)
      expect(endEvent.defaultPrevented).toBe(false)
      expect(navigation.activeIndex.value).toBe(0)
    })
  })

  describe('settled results', () => {
    it('opens and resets the active option when hits change', async () => {
      const { hits, navigation } = setup([makeHit('a')])
      navigation.handleKeydown(keydown('ArrowDown'))
      expect(navigation.activeIndex.value).toBe(0)

      hits.value = [makeHit('x'), makeHit('y')]
      await nextTick()

      expect(navigation.isOpen.value).toBe(true)
      expect(navigation.activeIndex.value).toBe(-1)
    })
  })

  describe('activeOptionId', () => {
    it('is null when there is no active option', () => {
      const { navigation } = setup([makeHit('a')])
      expect(navigation.activeOptionId.value).toBeNull()
    })

    it('matches optionId for the active index', () => {
      const { navigation } = setup([makeHit('a')])
      navigation.handleKeydown(keydown('ArrowDown'))

      expect(navigation.activeOptionId.value).toBe(navigation.optionId(0))
    })
  })

  describe('reset', () => {
    it('clears the active option without closing', () => {
      const { navigation } = setup([makeHit('a')])
      navigation.handleKeydown(keydown('ArrowDown'))

      navigation.reset()

      expect(navigation.activeIndex.value).toBe(-1)
      expect(navigation.isOpen.value).toBe(true)
    })
  })
})

describe('describeResultCount', () => {
  it('reports an error over any count', () => {
    expect(describeResultCount(5, true)).toBe('Nie udało się pobrać podpowiedzi')
  })

  it('reports zero results', () => {
    expect(describeResultCount(0, false)).toBe('Brak podpowiedzi')
  })

  it('uses the singular form for exactly one result', () => {
    expect(describeResultCount(1, false)).toBe('Znaleziono 1 podpowiedź')
  })

  it('uses the plural form for more than one result', () => {
    expect(describeResultCount(12, false)).toBe('Znaleziono 12 podpowiedzi')
  })
})
