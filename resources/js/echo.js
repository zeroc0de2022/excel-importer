import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb is proxied by nginx on the same host and port as the app,
// so the connection settings come from the current page URL.
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: document.querySelector('meta[name="reverb-key"]')?.content,
    wsHost: window.location.hostname,
    wsPort: window.location.port || 80,
    wssPort: window.location.port || 443,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],
});
