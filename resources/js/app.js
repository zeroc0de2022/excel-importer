import { createApp } from 'vue';
import './echo';
import ImportsPage from './components/ImportsPage.vue';
import RowsPage from './components/RowsPage.vue';

const pages = { imports: ImportsPage, rows: RowsPage };
const el = document.getElementById('app');

if (el) {
    createApp(pages[el.dataset.page], { ...el.dataset }).mount(el);
}
