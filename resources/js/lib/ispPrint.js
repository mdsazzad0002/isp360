import { printDocument } from './print';
import { money, fmtDate, label, escapeHtml as e, fmtMoney, invoiceTaxes } from './isp';

const box = 'border:1px solid #cbd5e1;border-collapse:collapse;padding:4px 6px;';

function partyBlock(customer) {
    return `
        <table style="width:100%;margin-bottom:10px;font-size:12px;">
            <tr>
                <td style="vertical-align:top;">
                    <strong>${e(customer?.name)}</strong><br/>
                    Code: ${e(customer?.code)}<br/>
                    Mobile: ${e(customer?.phone)}<br/>
                    ${e(customer?.billing_address || customer?.address || '')}
                </td>
            </tr>
        </table>`;
}

export function printInvoice(invoice, company, customerBalance = null) {
    const items = (invoice.items || [])
        .map((it, i) => `<tr><td style="${box}">${i + 1}</td><td style="${box}">${e(it.description)}</td><td style="${box}text-align:right;">${money(it.unit_price)}</td><td style="${box}text-align:right;">${Number(it.quantity)}</td><td style="${box}text-align:right;">${money(it.discount)}</td><td style="${box}text-align:right;">${money(it.total)}</td></tr>`)
        .join('');
    const row = (k, v, bold = false) => `<tr><td style="${box}text-align:right;${bold ? 'font-weight:bold;' : ''}" colspan="5">${k}</td><td style="${box}text-align:right;${bold ? 'font-weight:bold;' : ''}">${v}</td></tr>`;
    // exclusive: each rate is added before the total; inclusive: the total already holds it
    const taxes = invoiceTaxes(invoice);
    const taxRows = (inclusive) => (Boolean(invoice.tax_inclusive) === inclusive ? taxes.map((t) => row(inclusive ? `Includes ${e(t.label)}` : e(t.label), money(t.amount))).join('') : '');
    const taxNo = company?.tax_number ? `${e(company.tax_label || 'Tax')} No: ${e(company.tax_number)}<br/>` : '';

    const body = `
        <table style="width:100%;font-size:12px;margin-bottom:8px;">
            <tr>
                <td>${partyBlock(invoice.customer)}</td>
                <td style="text-align:right;vertical-align:top;">
                    ${taxNo}Invoice No: <strong>${e(invoice.invoice_no)}</strong><br/>
                    Invoice Date: ${fmtDate(invoice.invoice_date)}<br/>
                    Due Date: <strong>${fmtDate(invoice.due_date)}</strong><br/>
                    ${invoice.period_start ? `Billing Period: ${fmtDate(invoice.period_start)} – ${fmtDate(invoice.period_end)}<br/>` : ''}
                    ${invoice.connection ? `Connection: ${e(invoice.connection.code)}${invoice.connection.pppoe_username ? ' / ' + e(invoice.connection.pppoe_username) : ''}<br/>` : ''}
                    Status: <strong>${label(invoice.status)}</strong>
                </td>
            </tr>
        </table>
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9;"><th style="${box}">#</th><th style="${box}text-align:left;">Description</th><th style="${box}">Unit Price</th><th style="${box}">Qty</th><th style="${box}">Discount</th><th style="${box}">Total</th></tr></thead>
            <tbody>
                ${items}
                ${row('Subtotal', money(invoice.subtotal))}
                ${Number(invoice.discount) ? row('Discount', '- ' + money(invoice.discount)) : ''}
                ${taxRows(false)}
                ${Number(invoice.adjustment) ? row('Adjustment (credit/debit notes)', money(invoice.adjustment)) : ''}
                ${row('Total', money(invoice.total), true)}
                ${taxRows(true)}
                ${row('Paid', money(invoice.paid))}
                ${row('Due', money(invoice.due), true)}
                ${customerBalance !== null ? row('Account balance (all invoices)', money(customerBalance)) : ''}
            </tbody>
        </table>
        ${invoice.notes ? `<p style="font-size:12px;margin-top:8px;">Note: ${e(invoice.notes)}</p>` : ''}
        <p style="font-size:11px;margin-top:24px;color:#64748b;">This is a computer generated invoice.</p>`;
    printDocument(taxes.length ? 'Tax Invoice' : 'Invoice', body, company);
}

export function printReceipt(payment, company, customerBalance = null) {
    const allocations = (payment.allocations || []).filter((a) => a.status === 'active');
    const allocRows = allocations
        .map((a) => `<tr><td style="${box}">${e(a.invoice?.invoice_no)}</td><td style="${box}">${a.invoice?.period_start ? fmtDate(a.invoice.period_start) + ' – ' + fmtDate(a.invoice.period_end) : ''}</td><td style="${box}text-align:right;">${money(a.amount)}</td></tr>`)
        .join('');
    const currentDue = customerBalance !== null ? Math.max(0, Number(customerBalance)) : null;
    const previousDue = currentDue !== null && payment.status === 'completed' ? currentDue + Number(payment.amount) : null;

    const body = `
        <table style="width:100%;font-size:12px;margin-bottom:8px;">
            <tr>
                <td>${partyBlock(payment.customer)}</td>
                <td style="text-align:right;vertical-align:top;">
                    Receipt No: <strong>${e(payment.receipt_no)}</strong><br/>
                    Date: ${fmtDate(payment.payment_date)}<br/>
                    Method: ${label(payment.method)}${payment.bank ? ' (' + e(payment.bank.name) + ')' : ''}<br/>
                    ${payment.transaction_id ? `Transaction ID: ${e(payment.transaction_id)}<br/>` : ''}
                    Received By: ${e(payment.received_by?.name || 'System')}<br/>
                    Status: <strong>${label(payment.status)}</strong>
                </td>
            </tr>
        </table>
        <div style="font-size:16px;margin:8px 0 12px;">Amount Received: <strong>${fmtMoney(payment.amount)}</strong></div>
        ${allocRows ? `<table style="width:100%;font-size:12px;border-collapse:collapse;"><thead><tr style="background:#f1f5f9;"><th style="${box}text-align:left;">Invoice</th><th style="${box}text-align:left;">Period</th><th style="${box}">Applied</th></tr></thead><tbody>${allocRows}</tbody></table>` : ''}
        ${Number(payment.unallocated) > 0 ? `<p style="font-size:12px;">Advance credit kept: ${fmtMoney(payment.unallocated)}</p>` : ''}
        ${previousDue !== null ? `<p style="font-size:12px;">Previous due: ${fmtMoney(previousDue)} &nbsp; | &nbsp; Current due: <strong>${fmtMoney(currentDue)}</strong></p>` : ''}
        <table style="width:100%;margin-top:48px;font-size:12px;"><tr><td>______________________<br/>Customer</td><td style="text-align:right;">______________________<br/>Authorized</td></tr></table>`;
    printDocument('Money Receipt', body, company);
}

export function printStatement(customer, statement, company, range = '') {
    const rows = (statement.rows || [])
        .map((r) => `<tr><td style="${box}">${fmtDate(r.entry_date)}</td><td style="${box}">${e(r.description)}</td><td style="${box}text-align:right;">${Number(r.debit) ? money(r.debit) : ''}</td><td style="${box}text-align:right;">${Number(r.credit) ? money(r.credit) : ''}</td><td style="${box}text-align:right;">${money(r.balance)}</td></tr>`)
        .join('');
    const body = `
        ${partyBlock(customer)}
        ${range ? `<p style="font-size:12px;">Period: ${range}</p>` : ''}
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9;"><th style="${box}">Date</th><th style="${box}text-align:left;">Description</th><th style="${box}">Debit</th><th style="${box}">Credit</th><th style="${box}">Balance</th></tr></thead>
            <tbody>
                <tr><td style="${box}" colspan="4">Opening balance</td><td style="${box}text-align:right;">${money(statement.opening)}</td></tr>
                ${rows}
                <tr style="font-weight:bold;"><td style="${box}" colspan="2">Total</td><td style="${box}text-align:right;">${money(statement.total_debit)}</td><td style="${box}text-align:right;">${money(statement.total_credit)}</td><td style="${box}text-align:right;">${money(statement.closing)}</td></tr>
            </tbody>
        </table>
        <p style="font-size:11px;color:#64748b;">Positive balance = amount due. Negative balance = advance credit.</p>`;
    printDocument('Customer Statement', body, company);
}
