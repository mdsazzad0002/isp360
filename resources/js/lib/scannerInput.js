// Types a remotely-scanned barcode into whatever input/textarea currently
// has focus, replacing its existing value — mirrors what a physical
// USB/Bluetooth barcode scanner does (it just "types" into the focused
// field), so every existing product-search-by-code field in the app already
// reacts to it with no extra wiring.
export function insertScannedValue(value) {
    const el = document.activeElement;
    const isTextInput =
        el && (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && ['text', 'search', 'number', 'tel', ''].includes(el.type)));

    if (!isTextInput || el.disabled || el.readOnly) {
        window.dispatchEvent(new CustomEvent('scanner:code', { detail: value }));
        return false;
    }

    // A fresh scan replaces whatever is already in the field rather than
    // being inserted at the cursor — a leftover value from a previous scan
    // (or manual typing) should never get concatenated with the new code.
    const newValue = value;

    // Native setter bypasses Vue's patched value setter on the element so the
    // framework's reactivity actually notices the change when we dispatch
    // the input event below (setting el.value directly does not).
    const proto = el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, newValue);

    const caret = newValue.length;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.setSelectionRange(caret, caret);

    // Physical barcode scanners are "keyboard wedges" — they type the code
    // then send Enter, which is what every existing barcode-search field in
    // the app (@keydown.enter / @keyup.enter) is already wired to react to.
    // Firing all three keyboard events covers whichever one a given field
    // happens to listen on.
    const enterInit = { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true };
    el.dispatchEvent(new KeyboardEvent('keydown', enterInit));
    el.dispatchEvent(new KeyboardEvent('keypress', enterInit));
    el.dispatchEvent(new KeyboardEvent('keyup', enterInit));

    return true;
}
