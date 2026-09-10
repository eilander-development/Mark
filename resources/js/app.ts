import '../css/app.css'
import { createApp } from 'vue'
import App from './App.vue'

const el = document.getElementById('ironforge-vue')
if (el) {
    createApp(App).mount(el)
}
