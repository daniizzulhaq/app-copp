import assert from 'node:assert/strict';
import test from 'node:test';
import { initDailyRoiRefresh } from '../../resources/js/daily-roi-refresh.js';

function page({ date = '2026-11-20', instant = '2026-11-20T16:59:59Z', serverInstant = instant, hasClaims = true } = {}) {
    const doc = new EventTarget();
    const win = new EventTarget();
    let current = new Date(instant);
    let tick;
    let reloads = 0;
    let busy = false;
    doc.visibilityState = 'visible';
    doc.querySelector = selector => selector === '[data-daily-roi-date]'
        ? (hasClaims ? { dataset: { dailyRoiDate: date, dailyRoiTimezone: 'Asia/Jakarta', dailyRoiNow: String(new Date(serverInstant).getTime()) } } : null)
        : (busy ? {} : null);
    win.location = { reload() { reloads++; } };
    win.setInterval = callback => { tick = callback; };
    initDailyRoiRefresh(doc, win, () => current);
    return {
        doc, win,
        setDate(value) { current = new Date(value); },
        setBusy(value) { busy = value; },
        tick() { tick?.(); },
        get reloads() { return reloads; },
        get hasTimer() { return Boolean(tick); },
    };
}

test('refreshes once when WIB reaches midnight, even though UTC is still the previous day', () => {
    const browser = page();
    browser.tick();
    assert.equal(browser.reloads, 0);
    browser.setDate('2026-11-20T17:00:00Z');
    browser.tick();
    browser.win.dispatchEvent(new Event('focus'));
    assert.equal(browser.reloads, 1);
});

test('a sleeping mobile tab refreshes on becoming visible the next day', () => {
    const browser = page();
    browser.doc.visibilityState = 'hidden';
    browser.setDate('2026-11-20T17:05:00Z');
    browser.tick();
    assert.equal(browser.reloads, 0);
    browser.doc.visibilityState = 'visible';
    browser.doc.dispatchEvent(new Event('visibilitychange'));
    assert.equal(browser.reloads, 1);
});

test('returning to yesterday\'s page from browser history refreshes claims', () => {
    const browser = page();
    browser.setDate('2026-11-21T03:00:00Z');
    browser.win.dispatchEvent(new Event('pageshow'));
    assert.equal(browser.reloads, 1);
});

test('an initially stale document is refreshed immediately', () => {
    const browser = page({ instant: '2026-11-21T03:00:00Z' });
    assert.equal(browser.reloads, 1);
});

test('waits for an in-progress form submission before refreshing', () => {
    const browser = page();
    browser.setBusy(true);
    browser.setDate('2026-11-20T17:00:00Z');
    browser.tick();
    assert.equal(browser.reloads, 0);
    browser.setBusy(false);
    browser.tick();
    assert.equal(browser.reloads, 1);
});

test('pages without daily member claims do not run a refresh timer', () => {
    const browser = page({ hasClaims: false });
    assert.equal(browser.hasTimer, false);
    assert.equal(browser.reloads, 0);
});

test('an incorrect phone clock does not cause repeated reloads or delay the server midnight', () => {
    const browser = page({ instant: '2024-01-01T00:00:00Z', serverInstant: '2026-11-20T16:59:59Z' });
    assert.equal(browser.reloads, 0);
    browser.setDate('2024-01-01T00:00:01Z');
    browser.tick();
    assert.equal(browser.reloads, 1);
});
