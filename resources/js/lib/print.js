// Prints a report/invoice fragment in a hidden iframe with a company letterhead header,
// replacing the old @include('layouts.headerInfo') + jQuery iframe pattern.
//
// The actual HTML/CSS wrapper comes from a print template (see ./printTemplates) so
// multiple invoice/report designs can be supported — pass `templateKey` to force one,
// otherwise the user's last-selected design (or "classic") is used.
import { getSelectedPrintTemplateKey, renderPrintTemplate } from './printTemplates';

// The invoice/report fragments are plain app markup styled with Tailwind
// utility classes — they only look right because the app's compiled CSS is
// loaded on the page. The print popup is a brand new blank document, so
// without this those classes are just dead attribute names: on-screen the
// invoice looks correct (rendered inside the app), but the printed/PDF
// output loses every border, color and layout rule. Cloning the current
// page's stylesheets into the popup is what makes the two match.
function collectAppStyles() {
    const nodes = Array.from(document.querySelectorAll('link[rel="stylesheet"], style'));
    return nodes
        .map((node) => {
            if (node.tagName === 'LINK') {
                // Use the `.href` property (not getAttribute) so it's
                // resolved to an absolute URL — the popup starts at
                // about:blank, where a relative href would 404.
                return `<link rel="stylesheet" href="${node.href}">`;
            }
            return node.outerHTML;
        })
        .join('\n');
}

const CLOSE_BAR_HTML = `
    <div class="quote-print-close-bar" style="position:fixed;top:10px;right:10px;z-index:9999;">
        <button type="button" onclick="window.close()" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;padding:6px 14px;border-radius:6px;border:none;background:#dc2626;color:#fff;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.3);">Close</button>
    </div>
    <style>@media print { .quote-print-close-bar { display: none !important; } }</style>
`;

// Prints in a real popup window rather than a hidden iframe — Firefox has
// several quirks around calling print() on a srcdoc iframe (it can silently
// no-op, or hijack the current tab into its in-content print-preview mode,
// which looks like the page reloading). A dedicated window sidesteps all of
// that and matches what users expect when they click Print.
export function printDocument(title, bodyHtml, company, subtitleHtml = '', templateKey = null, headerOptions = {}) {
    // window.open must be called synchronously in the click handler or popup
    // blockers will kill it. Explicit chrome-less popup features matter beyond
    // cosmetics: browsers are far more willing to let a script-closed window
    // actually close when it was opened as a real popup rather than a plain tab.
    const printWindow = window.open(
        '',
        '_blank',
        'width=900,height=1000,toolbar=no,location=no,menubar=no,status=no,scrollbars=yes,resizable=yes'
    );
    if (!printWindow) {
        console.error('[print] window.open returned null — popup blocked?');
        return;
    }

    const autoClose = company?.close_print_window_status !== 'inactive';

    // Closing is driven FROM INSIDE the popup's own script, not from this
    // (opener) script. Browsers require "user activation" for window.close()
    // to actually take effect, and that activation is tracked per-window — a
    // click on the print button here doesn't carry over to the popup, so
    // calling printWindow.close() from here gets silently ignored.
    // window.print() itself blocks the popup's own script until the print
    // dialog is dismissed, and closing immediately after that — still inside
    // the popup's own script — is the one path that reliably works, mirroring
    // how the manual Close button (a click inside the popup) always succeeds.
    const script = `
        <script>
        (function () {
            function waitForImages(doc, timeoutMs) {
                var images = Array.prototype.slice.call(doc.querySelectorAll('img'));
                var pending = images.filter(function (img) { return !img.complete; });
                if (pending.length === 0) return Promise.resolve();
                return Promise.race([
                    Promise.all(pending.map(function (img) {
                        return new Promise(function (resolve) {
                            img.addEventListener('load', resolve, { once: true });
                            img.addEventListener('error', resolve, { once: true });
                        });
                    })),
                    new Promise(function (resolve) { setTimeout(resolve, timeoutMs); }),
                ]);
            }
            window.addEventListener('load', function () {
                waitForImages(document, 5000).then(function () {
                    var closed = false;
                    function closeOnce() {
                        if (closed) return;
                        closed = true;
                        if (${autoClose}) window.close();
                    }
                    // Chrome/Edge can return control from window.print() before the print
                    // dialog has actually rendered (especially on heavier invoices), so
                    // closing right after print() sometimes kills the window before the
                    // dialog ever shows up. afterprint fires only once the dialog is
                    // actually dismissed, so wait for that instead. The timeout is just a
                    // safety net in case afterprint never fires for some reason.
                    window.addEventListener('afterprint', closeOnce, { once: true });
                    setTimeout(closeOnce, 60000);
                    window.focus();
                    window.print();
                });
            });
        })();
        <\/script>
    `;

    let html = renderPrintTemplate(templateKey || getSelectedPrintTemplateKey(), {
        title,
        bodyHtml,
        company,
        subtitleHtml,
        hideLetterhead: !!headerOptions.hideLetterhead,
        hideTitle: !!headerOptions.hideTitle,
    });
    const appStyles = collectAppStyles();
    html = html.includes('</head>') ? html.replace('</head>', appStyles + '</head>') : appStyles + html;
    // A page can always close itself via a click inside it, even in browsers
    // that block the opener from closing it via script — guaranteed fallback
    // for when auto-close doesn't work.
    const inject = CLOSE_BAR_HTML + script;
    html = html.includes('</body>') ? html.replace('</body>', inject + '</body>') : html + inject;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
}
