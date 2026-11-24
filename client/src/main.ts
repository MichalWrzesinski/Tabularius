import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'
import { startMonitoring } from './lib/monitoring'
import './styles/tokens.css'
import './styles/base.css'
import './styles/mockup.css'
import './styles/extras.css'

const app = createApp(App).use(router)
void startMonitoring(app)
app.mount('#app')
