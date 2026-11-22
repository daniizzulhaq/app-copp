// A dashboard left open overnight must request the new day's claims from the server.
export function initDailyRoiRefresh(doc = document, win = window, now = () => new Date()) {
    const section = doc.querySelector('[data-daily-roi-date]');
    if (!section) return;

    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: section.dataset.dailyRoiTimezone,
        year: 'numeric', month: '2-digit', day: '2-digit',
    });
    const clientStart = now().getTime();
    const serverStart = Number(section.dataset.dailyRoiNow) || clientStart;
    let reloading = false;
    const refreshIfNewDay = () => {
        if (reloading || doc.visibilityState === 'hidden' || doc.querySelector('form[aria-busy="true"]')) return;
        // Follow the server's clock even if the member's phone has an incorrect date.
        const serverNow = new Date(serverStart + now().getTime() - clientStart);
        const parts = Object.fromEntries(formatter.formatToParts(serverNow).map(part => [part.type, part.value]));
        const date = `${parts.year}-${parts.month}-${parts.day}`;
        if (date !== section.dataset.dailyRoiDate) {
            reloading = true;
            win.location.reload();
        }
    };
    doc.addEventListener('visibilitychange', refreshIfNewDay);
    win.addEventListener('pageshow', refreshIfNewDay);
    win.addEventListener('focus', refreshIfNewDay);
    win.setInterval(refreshIfNewDay, 30000);
    refreshIfNewDay();
}
