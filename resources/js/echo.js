import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref } from 'vue';

/**
 * Real-time connection to the Reverb WebSocket server (php artisan reverb:start).
 * Optional: without VITE_REVERB_APP_KEY, or when the server is down, `echo` stays null / disconnected
 * and the notification bell keeps polling instead.
 */
export const realtimeConnected = ref(false);
let echo = null;

export function getEcho() {
    if (echo || !import.meta.env.VITE_REVERB_APP_KEY) return echo;
    window.Pusher = Pusher;
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // private channels are authorised by Laravel (routes/channels.php); axios sends the XSRF cookie
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                axios.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                    .then((r) => callback(null, r.data))
                    .catch((e) => callback(e));
            },
        }),
    });
    const conn = echo.connector.pusher.connection;
    conn.bind('state_change', ({ current }) => (realtimeConnected.value = current === 'connected'));
    return echo;
}
