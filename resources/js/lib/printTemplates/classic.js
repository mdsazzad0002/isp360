// The original/default print layout: a bordered letterhead box (logo + company
// info) followed by a centered title and the report body. Kept byte-for-byte
// equivalent to the pre-multi-template output so existing prints don't shift.
export default function classicTemplate({ title, bodyHtml, company, subtitleHtml, hideLetterhead, hideTitle }) {
    const c = company || {};
    // hideLetterhead/hideTitle are set by callers (e.g. sale/purchase invoice designs)
    // whose bodyHtml already renders its own company letterhead and/or title bar —
    // otherwise this template's own letterhead/<h3> would print alongside it, doubling up.
    const letterhead = hideLetterhead ? '' : `
            <div class="letterhead">
                <img src="/${c.logo || 'noImage.jpg'}" />
                <div>
                    <h2>${c.title || ''}</h2>
                    <p><strong>Mobile:</strong> ${c.phone || ''}</p>
                    <p>${c.address || ''}</p>
                </div>
            </div>
    `;
    const header = letterhead + (hideTitle ? '' : `<h3>${title}</h3>`);
    return `
        <html>
        <head>
            <style>
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; padding: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #999; padding: 4px 6px; }
                th { background: #f0f0f0; }
                .letterhead { display: flex; gap: 12px; align-items: center; border: 1px solid #999; border-radius: 8px; padding: 8px; margin-bottom: 10px; }
                .letterhead img { width: 64px; height: 64px; object-fit: cover; border-radius: 5px; }
                .letterhead h2 { margin: 0; font-size: 16px; }
                .letterhead p { margin: 0; font-size: 11px; }
                h3 { text-align: center; margin: 8px 0; }
            </style>
        </head>
        <body>
            ${header}
            ${subtitleHtml || ''}
            ${bodyHtml}
        </body>
        </html>
    `;
}
