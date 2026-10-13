// Count-up for the headline value in a stat band (design.md motion stance).
// Numbers are formatted server-side; the script only animates the digits,
// so the resting DOM is always the real value even with JS off.
const countUp = (el) => {
    const target = Number(el.dataset.countup);
    const text = el.textContent;
    const match = text.match(/[\d,]+(\.\d+)?/);

    if (!Number.isFinite(target) || !match) return;

    const decimals = match[1] ? match[1].length - 1 : 0;
    const format = (n) => n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    const start = performance.now();
    const duration = 1100;

    const tick = (now) => {
        const p = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = text.replace(match[0], format(target * eased));
        if (p < 1) requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
};

const run = () => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    document.querySelectorAll('[data-countup]:not([data-counted])').forEach((el) => {
        el.dataset.counted = '1';
        countUp(el);
    });
};

document.addEventListener('DOMContentLoaded', run);
document.addEventListener('livewire:navigated', run);

// A card image that fails to load (tcgdex outage, a deleted photo) falls
// back to design.md's empty-image state instead of a blank frame; a
// decorative image (set logo) marked data-optional simply disappears.
const EMPTY_STATE_MARKUP =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<rect x="3.5" y="2.5" width="17" height="19" rx="2.5"></rect><path d="M3.5 16l4.5-4.5 3.5 3.5"></path>'
    + '<circle cx="15" cy="8" r="1.6"></circle><path d="M2 22L22 2"></path></svg><span>Image unavailable</span>';

const degradeImage = (img) => {
    if (img.dataset.degraded) return;
    img.dataset.degraded = '1';

    if ('optional' in img.dataset) {
        img.remove();
        return;
    }

    const wrap = img.closest('.imgwrap');
    if (!wrap || wrap.classList.contains('empty')) return;

    wrap.classList.add('empty');
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', `Image unavailable for ${img.alt || 'this card'}`);
    img.remove();
    // Static markup only — nothing from the page or the image goes in here.
    wrap.insertAdjacentHTML('beforeend', EMPTY_STATE_MARKUP);
};

// Capture phase, because `error` events on <img> do not bubble.
document.addEventListener('error', (event) => {
    if (event.target instanceof HTMLImageElement) degradeImage(event.target);
}, true);

// Images that already failed before this module ran (it is deferred).
const sweepBrokenImages = () => {
    document.querySelectorAll('img').forEach((img) => {
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) degradeImage(img);
    });
};
document.addEventListener('DOMContentLoaded', sweepBrokenImages);
document.addEventListener('livewire:navigated', sweepBrokenImages);

// Livewire's morph resets an <img>'s class to the server HTML (no
// is-loaded) while its src is unchanged, so onload/onerror never fire again
// and app.css keeps the image at opacity 0. After every morph, put the class
// back on images that already settled (`complete` covers loaded and errored).
// It lands in the same tick as the reset, before a style recalc, so the
// opacity transition does not replay.
const restoreLoadedImages = (root) => {
    root.querySelectorAll('.imgwrap img:not(.is-loaded)').forEach((img) => {
        if (img.complete) img.classList.add('is-loaded');
    });
};

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morphed', ({ el }) => restoreLoadedImages(el));
});

// Hero cursor-spotlight (design.md's HP3 pattern, scoped to the hero
// only). A no-op on touch devices — there's no pointermove to track, so
// the glow simply stays at its CSS default position, which is fine.
const bindHeroSpotlight = () => {
    const hero = document.querySelector('[data-hero-spotlight]');
    if (!hero || hero.dataset.spotlightBound) return;
    hero.dataset.spotlightBound = '1';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    hero.addEventListener('pointermove', (e) => {
        const r = hero.getBoundingClientRect();
        hero.style.setProperty('--mx', `${e.clientX - r.left}px`);
        hero.style.setProperty('--my', `${e.clientY - r.top}px`);
    });
};

document.addEventListener('DOMContentLoaded', bindHeroSpotlight);
document.addEventListener('livewire:navigated', bindHeroSpotlight);

// The nav's fade-edge hint (app.css .nw-nav.is-scrollable::after) must
// only show when the nav genuinely overflows — a username long enough
// to push it into scroll on a narrow viewport, not every viewport.
// Re-checked on resize since a real overflow/no-overflow boundary
// depends on both viewport width and the current username's length.
const markScrollableNav = () => {
    document.querySelectorAll('.nw-nav').forEach((nav) => {
        nav.classList.toggle('is-scrollable', nav.scrollWidth > nav.clientWidth + 1);
    });
};

document.addEventListener('DOMContentLoaded', markScrollableNav);
document.addEventListener('livewire:navigated', markScrollableNav);
window.addEventListener('resize', markScrollableNav);

// Theme toggle. The attribute itself is set synchronously by an inline
// <head> script (before this module loads) so there's no flash of the
// wrong theme; this only handles the click. No stored preference means
// "follow the OS", which app.css's prefers-color-scheme block already
// does on its own — this never writes a value until the user actually
// clicks the toggle.
const THEME_KEY = 'tcg-vault-theme';

const applyTheme = (theme) => {
    if (theme) document.documentElement.setAttribute('data-theme', theme);
    else document.documentElement.removeAttribute('data-theme');
};

const currentTheme = () => document.documentElement.getAttribute('data-theme')
    || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

const bindThemeToggle = () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        if (btn.dataset.themeBound) return;
        btn.dataset.themeBound = '1';
        btn.addEventListener('click', () => {
            const next = currentTheme() === 'dark' ? 'light' : 'dark';
            localStorage.setItem(THEME_KEY, next);
            applyTheme(next);
        });
    });
};

document.addEventListener('DOMContentLoaded', bindThemeToggle);
document.addEventListener('livewire:navigated', bindThemeToggle);

// wire:navigate swaps the document without re-running the inline <head>
// script that sets data-theme from localStorage on a full load — so a
// stored preference that disagrees with prefers-color-scheme gets lost
// on every SPA navigation until the next hard reload. Re-apply it here,
// same event bindThemeToggle already re-binds on.
document.addEventListener('livewire:navigated', () => applyTheme(localStorage.getItem(THEME_KEY)));

// Sparkline tooltip: nearest-point tracking on pointermove, matching the
// x-sparkline component's own coordinate space (a fixed 600-wide viewBox
// regardless of the rendered width) so we scale by clientWidth instead of
// re-deriving points from the DOM.
const bindSparklineTooltips = () => {
    document.querySelectorAll('[data-sparkline]').forEach((wrap) => {
        if (wrap.dataset.sparklineBound) return;
        wrap.dataset.sparklineBound = '1';

        let points;
        try {
            points = JSON.parse(wrap.dataset.sparkline);
        } catch {
            return;
        }
        if (!points.length) return;

        const svg = wrap.querySelector('svg');
        const guide = wrap.querySelector('[data-guide]');
        const dot = wrap.querySelector('[data-hover-dot]');
        const endDot = wrap.querySelector('[data-end-dot]');
        const tip = wrap.querySelector('[data-tip]');
        const tipDate = wrap.querySelector('[data-tip-date]');
        const tipValue = wrap.querySelector('[data-tip-value]');
        const viewboxWidth = svg.viewBox.baseVal.width || 600;

        const nearest = (clientX) => {
            const rect = svg.getBoundingClientRect();
            const x = ((clientX - rect.left) / rect.width) * viewboxWidth;
            let closest = points[0];
            let closestDist = Infinity;
            for (const p of points) {
                const dist = Math.abs(p.x - x);
                if (dist < closestDist) { closest = p; closestDist = dist; }
            }
            return { point: closest, rectWidth: rect.width };
        };

        const show = (clientX) => {
            const { point, rectWidth } = nearest(clientX);
            guide.setAttribute('x1', point.x);
            guide.setAttribute('x2', point.x);
            guide.setAttribute('opacity', '1');
            dot.setAttribute('cx', point.x);
            dot.setAttribute('cy', point.y);
            dot.setAttribute('opacity', '1');
            if (endDot) endDot.style.opacity = point.x === points[points.length - 1].x ? '1' : '0.35';

            tipDate.textContent = point.date;
            tipValue.textContent = point.value;
            tip.hidden = false;
            const tipWidth = tip.offsetWidth;
            const scale = rectWidth / viewboxWidth;
            let left = point.x * scale - tipWidth / 2;
            left = Math.max(0, Math.min(rectWidth - tipWidth, left));
            tip.style.left = `${left}px`;
        };

        const hide = () => {
            guide.setAttribute('opacity', '0');
            dot.setAttribute('opacity', '0');
            if (endDot) endDot.style.opacity = '1';
            tip.hidden = true;
        };

        svg.addEventListener('pointermove', (e) => show(e.clientX));
        svg.addEventListener('pointerdown', (e) => show(e.clientX));
        svg.addEventListener('pointerleave', hide);
    });
};

document.addEventListener('DOMContentLoaded', bindSparklineTooltips);
document.addEventListener('livewire:navigated', bindSparklineTooltips);

// The "Saved" tag next to an autosaved field (variant-row partial) —
// shown for ~2s and faded, mirroring the discreet feedback the old
// inline Qty/Notes editing gave with no persistent banner.
document.addEventListener('row-saved', (event) => {
    const rows = document.querySelectorAll(`[wire\\:key="editingRows.${event.detail.index}"]`);
    rows.forEach((row) => {
        const tag = row.querySelector('[data-autosave-tag]');
        if (!tag) return;
        tag.style.display = 'flex';
        tag.style.opacity = '1';
        clearTimeout(tag._fadeTimer);
        tag._fadeTimer = setTimeout(() => { tag.style.opacity = '0'; }, 1600);
    });
});

// Set grid: one-tap quick add with an undo toast stack. The server owns
// every count; a chip only changes after a confirmed response, so the page
// never shows a +1 the database does not hold.
const TOAST_MS = 6000;
const FAILURE_MS = 4000;
// A slow save escalates its wording; the chip stays blocked until the
// request really settles, so a copy can never be added twice by a retap.
const SLOW_MS = 10000;
const STALLED_MS = 30000;
const MAX_TOASTS = 4;
const FAILED_LABEL = 'Not saved — retry';
const SAVING_LABEL = 'Still saving…';
const STALLED_LABEL = 'Still saving — check your connection';
const QUICK_METHODS = ['quickAdd', 'undoQuickAdd'];

const setGridData = () => ({
    toasts: [],
    nextToastId: 1,
    busy: new Set(),
    timers: new Map(),
    pending: [],
    unhook: null,
    unhookMorph: null,
    refreshQueued: false,

    init() {
        // Livewire's promise for a call never settles when its request fails,
        // so failures are caught on the request itself and settle the
        // matching pending calls. Only requests made up entirely of this
        // component's quick add / undo calls lose Livewire's error modal.
        this.unhook = window.Livewire.hook('request', ({ payload, fail }) => {
            fail(({ status, preventDefault }) => this.settleFailedRequest(payload, status, preventDefault));
        });

        // A render that was already in flight when a chip was tapped morphs
        // the chip from server HTML that has neither the busy flag nor the
        // "Still saving…" / "Not saved" note; put both back.
        this.unhookMorph = window.Livewire.hook('morphed', ({ component }) => {
            if (component.id !== this.$wire.id) return;
            this.busy.forEach((key) => {
                const [cardId, ...rest] = key.split('|');
                this.chipFor(cardId, rest.join('|'))?.setAttribute('aria-busy', 'true');
            });
            this.$root.querySelectorAll('[data-chip]').forEach((chip) => this.restoreNote(chip));
        });
    },

    restoreNote(chip) {
        if (chip._note === undefined) return;
        const labelEl = chip.querySelector('[data-chip-label]');
        if (labelEl.textContent !== chip._note) {
            chip._originalLabel = labelEl.textContent;
            labelEl.textContent = chip._note;
        }
        if (chip._note === FAILED_LABEL) chip.classList.add('failed');
    },

    // Quiet enough to redraw: every quick call in flight has been waiting
    // past the last escalation (STALLED_MS). Such a call keeps its chip
    // blocked (the save may still happen), but must not hold redraws back
    // for good, which a request the failure hook could not match would.
    idle() {
        return this.pending.every((entry) => entry.stuck);
    },

    // The import banner reports new cards: redraw the grid now when no quick
    // add or undo is in flight, otherwise once they have all settled, so a
    // redraw never lands on a chip that is waiting for the server.
    refreshWhenIdle() {
        if (this.idle()) {
            this.refreshQueued = false;
            this.$wire.$refresh();
            return;
        }
        this.refreshQueued = true;
    },

    flushQueuedRefresh() {
        if (this.refreshQueued && this.idle()) this.refreshWhenIdle();
    },

    destroy() {
        this.unhook?.();
        this.unhookMorph?.();
        this.timers.forEach((timer) => clearTimeout(timer));
        this.pending.splice(0).forEach((entry) => entry.dispose());
        this.$root.querySelectorAll('[data-chip]').forEach((chip) => clearTimeout(chip._failTimer));
    },

    settleFailedRequest(payload, status, preventDefault) {
        let components;
        try {
            components = JSON.parse(payload).components ?? [];
        } catch (error) {
            return;
        }

        let ours = true;
        const failed = [];

        for (const component of components) {
            let id = null;
            try {
                id = JSON.parse(component.snapshot).memo.id;
            } catch (error) {
                // An unreadable snapshot is not ours.
            }
            const calls = component.calls ?? [];

            if (id !== this.$wire.id) {
                ours = false;
                continue;
            }

            // Whatever else shares the request, this grid's quick calls in it
            // failed with it; the extra work only decides whether Livewire's
            // error modal may be suppressed.
            failed.push(...calls.filter((call) => QUICK_METHODS.includes(call.method)));

            const quickOnly = calls.length > 0 && calls.every((call) => QUICK_METHODS.includes(call.method));
            if (!quickOnly || (component.updates && Object.keys(component.updates).length > 0)) ours = false;
        }

        // One failed call settles exactly one pending call: the oldest with
        // the same method and params, which is the order Livewire sends them.
        let matched = false;
        for (const call of failed) {
            const entry = this.pending.find((candidate) => candidate.method === call.method && JSON.stringify(candidate.params) === JSON.stringify(call.params));
            if (!entry) continue;
            matched = true;
            entry.fail(new Error(`request failed: ${status}`));
        }

        // Only transport failures and server errors are handled here; 401,
        // 403 and 419 keep Livewire's own login / refresh handling.
        const hidesModal = !status || status === 429 || status >= 500;
        if (matched && ours && hidesModal) preventDefault();
    },

    // Resolves with the server's answer and rejects when the request fails.
    // It never gives up on its own: until Livewire answers or the failure
    // hook settles it, the save may still happen, so onSlow only lets the
    // caller escalate its wording ('slow', then 'stalled').
    call(method, params, { onSlow } = {}) {
        return new Promise((resolve, reject) => {
            const timers = [];
            let settled = false;
            const entry = {
                method,
                params,
                stuck: false,
                dispose: () => {
                    settled = true;
                    timers.forEach(clearTimeout);
                    this.pending = this.pending.filter((candidate) => candidate !== entry);
                },
                fail: (error) => {
                    if (settled) return;
                    entry.dispose();
                    reject(error);
                },
            };

            this.pending.push(entry);
            timers.push(setTimeout(() => onSlow?.('slow'), SLOW_MS));
            timers.push(setTimeout(() => {
                entry.stuck = true;
                onSlow?.('stalled');
                this.flushQueuedRefresh();
            }, STALLED_MS));

            this.$wire[method](...params).then((value) => {
                if (settled) return;
                entry.dispose();
                resolve(value);
            }, entry.fail);
        });
    },

    chipFor(cardId, variant) {
        return [...this.$root.querySelectorAll(`[data-card="${Number(cardId)}"][data-chip]`)]
            .find((chip) => chip.dataset.variant === (variant ?? ''));
    },

    paintChip(chip, count) {
        if (!chip) return;
        const label = chip.dataset.label;
        chip.dataset.count = String(count);
        chip.classList.toggle('owned', count > 0);
        chip.setAttribute('aria-label', `Add one ${label || 'copy'} of ${chip.dataset.name}, you have ${count}`);
        chip.querySelector('[data-chip-label]').textContent = count > 0 ? (label || '+1') : (label ? `+ ${label}` : '+1');
        const countEl = chip.querySelector('[data-chip-count]');
        countEl.textContent = `×${count}`;
        countEl.hidden = count < 1;
    },

    paintTile(cardId, cardTotal) {
        const tile = this.$root.querySelector(`[data-tile="${Number(cardId)}"]`);
        if (!tile) return;
        tile.classList.toggle('ghost', cardTotal < 1);
        const qty = tile.querySelector('[data-tile-qty]');
        qty.textContent = `×${cardTotal}`;
        qty.hidden = cardTotal < 1;
        tile.querySelector('[data-tile-missing]').hidden = cardTotal > 0;
    },

    // A chip shows at most one note in place of its label ("Still saving…",
    // "Not saved — retry"); clearing it restores the label unless a
    // re-render has already replaced it.
    setNote(chip, text) {
        this.clearFailure(chip);
        const labelEl = chip.querySelector('[data-chip-label]');
        chip._originalLabel = labelEl.textContent;
        chip._note = text;
        labelEl.textContent = text;
    },

    clearFailure(chip) {
        clearTimeout(chip._failTimer);
        const labelEl = chip.querySelector('[data-chip-label]');
        if (chip._originalLabel !== undefined && labelEl.textContent === chip._note) {
            labelEl.textContent = chip._originalLabel;
        }
        delete chip._originalLabel;
        delete chip._note;
        chip.classList.remove('failed');
    },

    fail(chip, message) {
        this.setNote(chip, FAILED_LABEL);
        chip.classList.add('failed');
        chip._failTimer = setTimeout(() => this.clearFailure(chip), FAILURE_MS);
        this.pushToast({ message: message ?? FAILED_LABEL, undoable: false });
    },

    // Paints a confirmed answer.
    applyAdd(chip, cardId, result) {
        if (!result || result.ok !== true) {
            this.fail(chip, result?.message);
            return;
        }

        this.clearFailure(chip);
        this.paintChip(chip, result.variantCount);
        this.paintTile(cardId, result.cardTotal);
        const label = chip.dataset.label ? ` · ${chip.dataset.label}` : '';
        this.pushToast({
            message: `Added ${chip.dataset.name}${label}`,
            subject: `${chip.dataset.name}${label}`,
            itemId: result.itemId,
            cardId,
            variant: chip.dataset.variant,
            undoable: true,
        });
    },

    async add(chip) {
        try {
            await this.addCopy(chip);
        } finally {
            this.flushQueuedRefresh();
        }
    },

    async addCopy(chip) {
        const cardId = Number(chip.dataset.card);
        // The chip's variant goes to the server verbatim; '' means "no variant".
        const variant = chip.dataset.variant;
        const key = `${cardId}|${variant}`;

        // The chip stays blocked until the server answers or the request
        // fails, so a slow save cannot be tapped twice.
        if (this.busy.has(key)) return;
        this.busy.add(key);
        this.clearFailure(chip);
        chip.setAttribute('aria-busy', 'true');

        let result;
        try {
            result = await this.call('quickAdd', [cardId, variant], {
                onSlow: (stage) => this.setNote(chip, stage === 'stalled' ? STALLED_LABEL : SAVING_LABEL),
            });
        } catch (error) {
            this.fail(this.chipFor(cardId, variant) ?? chip);
            return;
        } finally {
            this.busy.delete(key);
            chip.removeAttribute('aria-busy');
            // A redraw while the request was in flight may have replaced the chip.
            this.chipFor(cardId, variant)?.removeAttribute('aria-busy');
        }

        this.applyAdd(this.chipFor(cardId, variant) ?? chip, cardId, result);
    },

    applyUndo(toast, result) {
        toast.held = 0;

        if (result?.ok === true) {
            this.paintChip(this.chipFor(toast.cardId, toast.variant), result.variantCount);
            this.paintTile(toast.cardId, result.cardTotal);
            this.dismiss(toast.id);
            return;
        }

        toast.message = result?.message ?? 'Could not undo that.';
        this.scheduleDismiss(toast.id);
    },

    // One click per toast: the undo is disabled before the request, and
    // stays disabled after any outcome, because a failed round trip may
    // still have been applied by the server.
    async undo(toast) {
        try {
            await this.undoCopy(toast);
        } finally {
            this.flushQueuedRefresh();
        }
    },

    async undoCopy(toast) {
        if (!toast.undoable) return;
        toast.undoable = false;
        // The toast must not vanish mid-request; its result gets its own full duration.
        this.cancelDismiss(toast.id);

        let result;
        try {
            result = await this.call('undoQuickAdd', [toast.itemId], {
                onSlow: (stage) => { toast.message = stage === 'stalled' ? 'Still undoing — check your connection' : 'Still undoing…'; },
            });
        } catch (error) {
            toast.held = 0;
            toast.message = 'Could not undo that. Check the chip count.';
            this.scheduleDismiss(toast.id);
            return;
        }

        this.applyUndo(toast, result);
    },

    pushToast(toast) {
        const entry = { id: this.nextToastId++, held: 0, ...toast };
        this.toasts.push(entry);
        if (this.toasts.length > MAX_TOASTS) {
            // Never evict a toast being read or about to be clicked; if every
            // older one is held, the stack briefly grows instead.
            const evictable = this.toasts.find((candidate) => candidate.id !== entry.id && candidate.held === 0);
            if (evictable) this.dismiss(evictable.id);
        }
        this.scheduleDismiss(entry.id);
    },

    // Hovering or focusing a toast keeps it open (a pointer or keyboard
    // user needs time to reach Undo); leaving restarts the full duration.
    hold(toast, on) {
        toast.held = Math.max(0, toast.held + (on ? 1 : -1));
        if (toast.held > 0) this.cancelDismiss(toast.id);
        else this.scheduleDismiss(toast.id);
    },

    scheduleDismiss(id) {
        this.cancelDismiss(id);
        const toast = this.toasts.find((candidate) => candidate.id === id);
        if (!toast || toast.held > 0 || toast.dismissing) return;
        this.timers.set(id, setTimeout(() => this.dismiss(id), TOAST_MS));
    },

    // A dismissed toast that holds focus would drop it to the page: hand it
    // to the toast's own chip if it is on screen, else to the toast region.
    keepFocus(id) {
        const el = this.$root.querySelector(`.nw-toasts [data-toast="${Number(id)}"]`);
        if (!el || !el.contains(document.activeElement)) return;
        const toast = this.toasts.find((candidate) => candidate.id === id);
        const chip = toast?.cardId !== undefined ? this.chipFor(toast.cardId, toast.variant) : null;
        (chip ?? this.$root.querySelector('.nw-toasts'))?.focus();
    },

    cancelDismiss(id) {
        clearTimeout(this.timers.get(id));
        this.timers.delete(id);
    },

    dismiss(id) {
        this.cancelDismiss(id);
        // Moving focus away fires focusout; a dismissing toast must not be rescheduled by it.
        const leaving = this.toasts.find((toast) => toast.id === id);
        if (leaving) leaving.dismissing = true;
        this.keepFocus(id);
        this.toasts = this.toasts.filter((toast) => toast.id !== id);
    },
});

const registerSetGrid = () => window.Alpine.data('setGrid', setGridData);

if (window.Alpine) {
    registerSetGrid();
} else {
    document.addEventListener('alpine:init', registerSetGrid);
}
