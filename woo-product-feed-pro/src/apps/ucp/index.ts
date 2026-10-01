import 'vite/modulepreload-polyfill';
import { createApp } from 'vue';
import './ucp.css';
import App from './App.vue';

/***************************************************************************
 * Mount the AI Discovery (UCP connector) admin app
 ***************************************************************************
 *
 * Localized config arrives on window.adtObjUcp (rest_url, nonce,
 * profile_url, orders_url) via the Vite_App enqueue in UCP_Page.
 */
const el = document.getElementById('adt-ucp-app');

if (el) {
  el.innerHTML = '';
  createApp(App).mount(el);
}
