import { createApp } from 'vue'
import { VueQueryPlugin } from '@tanstack/vue-query'
import SearchScreen from './SearchScreen.vue'
import { createAppQueryClient } from './queryClient'
import './styles.css'

const app = createApp(SearchScreen)

app.use(VueQueryPlugin, {
  queryClient: createAppQueryClient(),
})

app.mount('#app')
