import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';

const pages = import.meta.glob<{ default: DefineComponent }>('./pages/*.vue');

void createInertiaApp({
    title: (title) => `${title} | Spendwise`,
    resolve: async (name) => (await pages[`./pages/${name}.vue`]()).default,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});
