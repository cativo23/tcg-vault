// DOM helpers the collection and card pages share. Pure logic stays in the *-helpers.js files under node --test.
import { fallbackSrc } from './collection-helpers.js';

/** Copies text through a hidden textarea and execCommand, for origins where the clipboard API is not available. */
export function legacyCopy(text) {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    document.body.removeChild(field);
    return ok;
}

/**
 * Image optional, data mandatory: a picture that fails becomes the hatched face with the card's name. A tcgdex
 * low.webp that is missing is retried once at high.webp before giving up on the picture.
 */
export function degradeImage(img, { pic = '.col-pic', empty = 'col-pic--empty' } = {}) {
    const frame = img.closest(pic);
    if (!frame || frame.classList.contains(empty)) return;

    const retry = img.dataset.retried ? null : fallbackSrc(img.getAttribute('src'));
    if (retry) {
        img.dataset.retried = '1';
        img.src = retry;
        return;
    }

    frame.classList.add(empty);
    const name = document.createElement('b');
    name.textContent = img.dataset.name || '';
    const note = document.createElement('span');
    note.textContent = 'No image';
    img.remove();
    frame.append(name, note);
}
