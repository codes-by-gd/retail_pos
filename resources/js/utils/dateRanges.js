/** Date presets shared by lists, reports and the dashboard. Local dates as YYYY-MM-DD. */
export const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export function datePresets(fyStart = '04-01', today = new Date()) {
    const y = today.getFullYear();
    const m = today.getMonth();
    const day = (offset) => new Date(y, m, today.getDate() + offset);
    const [fm, fd] = fyStart.split('-').map(Number);
    let fy = new Date(y, fm - 1, fd);
    if (today < fy) fy = new Date(y - 1, fm - 1, fd);
    const monday = day(-((today.getDay() + 6) % 7));
    return {
        today: ['Today', iso(today), iso(today)],
        yesterday: ['Yesterday', iso(day(-1)), iso(day(-1))],
        week: ['This week', iso(monday), iso(today)],
        last7: ['Last 7 days', iso(day(-6)), iso(today)],
        month: ['This month', iso(new Date(y, m, 1)), iso(today)],
        last_month: ['Last month', iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))],
        quarter: ['This quarter', iso(new Date(y, Math.floor(m / 3) * 3, 1)), iso(today)],
        fy: ['This financial year', iso(fy), iso(today)],
        last_fy: ['Last financial year', iso(new Date(fy.getFullYear() - 1, fm - 1, fd)), iso(new Date(fy.getFullYear(), fm - 1, fd - 1))],
    };
}

/** Which preset (if any) a from/to pair is. */
export const matchPreset = (presets, from, to) => Object.entries(presets).find(([, [, f, t]]) => f === from && t === to)?.[0] ?? (from || to ? 'custom' : 'any');
