import { computed, nextTick, ref, watch } from 'vue'
import type { Ref } from 'vue'
import type { SearchHit } from '../api/types'
import { formatResultCount, searchStatusMessages } from '../format/labels'

export type ComboboxNavigation = {
  isOpen: Readonly<Ref<boolean>>
  activeIndex: Readonly<Ref<number>>
  activeOptionId: Readonly<Ref<string | null>>
  listboxId: string
  open: () => void
  close: () => void
  reset: () => void
  handleKeydown: (event: KeyboardEvent) => void
  optionId: (index: number) => string
}

export type ComboboxNavigationParams = {
  hits: Readonly<Ref<readonly SearchHit[]>>
  onSelect: (hit: SearchHit) => void
  onClear: () => void
}

const NO_ACTIVE_OPTION = -1

const LISTBOX_ID = 'search-combobox-listbox'

export function useComboboxNavigation(
  params: ComboboxNavigationParams,
): ComboboxNavigation {
  const isOpen = ref(false)
  const activeIndex = ref(NO_ACTIVE_OPTION)

  function optionId(index: number): string {
    return `${LISTBOX_ID}-option-${index}`
  }

  const activeOptionId = computed<string | null>(() =>
    activeIndex.value === NO_ACTIVE_OPTION ? null : optionId(activeIndex.value),
  )

  function open(): void {
    isOpen.value = true
  }

  function close(): void {
    isOpen.value = false
    activeIndex.value = NO_ACTIVE_OPTION
  }

  function reset(): void {
    activeIndex.value = NO_ACTIVE_OPTION
  }

  watch(params.hits, () => {
    reset()
    open()
  })

  function scrollActiveOptionIntoView(): void {
    void nextTick(() => {
      if (activeIndex.value === NO_ACTIVE_OPTION) return
      document
        .getElementById(optionId(activeIndex.value))
        ?.scrollIntoView({ block: 'nearest' })
    })
  }

  function activateFirst(): void {
    activeIndex.value = params.hits.value.length > 0 ? 0 : NO_ACTIVE_OPTION
    scrollActiveOptionIntoView()
  }

  function activateLast(): void {
    const count = params.hits.value.length
    activeIndex.value = count > 0 ? count - 1 : NO_ACTIVE_OPTION
    scrollActiveOptionIntoView()
  }

  function moveActiveIndex(delta: number): void {
    const count = params.hits.value.length
    if (count === 0) {
      activeIndex.value = NO_ACTIVE_OPTION
      return
    }
    activeIndex.value = (activeIndex.value + delta + count) % count
    scrollActiveOptionIntoView()
  }

  function selectActiveOption(): void {
    const hit = params.hits.value[activeIndex.value]
    if (hit === undefined) return
    params.onSelect(hit)
    close()
  }

  function handleKeydown(event: KeyboardEvent): void {
    switch (event.key) {
      case 'ArrowDown': {
        event.preventDefault()
        if (isOpen.value) {
          moveActiveIndex(1)
        } else {
          open()
          activateFirst()
        }
        break
      }
      case 'ArrowUp': {
        event.preventDefault()
        if (isOpen.value) {
          moveActiveIndex(-1)
        } else {
          open()
          activateLast()
        }
        break
      }
      case 'Enter': {
        if (isOpen.value && activeIndex.value !== NO_ACTIVE_OPTION) {
          event.preventDefault()
          selectActiveOption()
        }
        break
      }
      case 'Escape': {
        if (isOpen.value) {
          close()
        } else {
          params.onClear()
        }
        break
      }
      case 'Tab': {
        if (isOpen.value) close()
        break
      }
      default:
        break
    }
  }

  return {
    isOpen,
    activeIndex,
    activeOptionId,
    listboxId: LISTBOX_ID,
    open,
    close,
    reset,
    handleKeydown,
    optionId,
  }
}

export function describeResultCount(count: number, isError: boolean): string {
  if (isError) return searchStatusMessages.error
  if (count === 0) return searchStatusMessages.empty
  return formatResultCount(count)
}
