/** Desktop (OS) notifications for alerts while the tab is in the background — opt-in per device. */
import { ref } from 'vue';

const KEY = 'notify-desktop';
const read = () => {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
};
export const desktopAlerts = ref(read());
export const desktopAlertsOn = () => desktopAlerts.value;
export const desktopSupported = () => typeof window !== 'undefined' && 'Notification' in window;

export async function setDesktopAlerts(on) {
    if (on && desktopSupported() && Notification.permission !== 'granted') {
        on = (await Notification.requestPermission()) === 'granted';
    }
    desktopAlerts.value = on;
    try {
        localStorage.setItem(KEY, on ? '1' : '0');
    } catch {
        /* ignore */
    }
    return on;
}
