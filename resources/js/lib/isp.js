import { usePage } from '@inertiajs/vue3';
import { useToast } from './toast';

// Billing currency of the branch in view, shared by HandleInertiaRequests.
export function currency() {
    return usePage().props?.currency || { code: 'BDT', symbol: 'Tk', decimals: 2 };
}

// "Tk" / "$" — for labels like "Amount (Tk)".
export function cur() {
    return currency().symbol;
}

// Smallest unit of the currency for number inputs: "0.01", "1" (JPY), "0.001" (KWD).
export function moneyStep() {
    const decimals = currency().decimals ?? 2;
    return decimals > 0 ? (1 / 10 ** decimals).toFixed(decimals) : '1';
}

// Rounds to the currency's smallest unit (for amounts put into inputs).
export function roundMoney(value) {
    return Number(Number(value || 0).toFixed(currency().decimals ?? 2));
}

// An invoice's tax per rate from its lines' snapshots: [{ label: 'VAT 15%', amount }].
export function invoiceTaxes(invoice) {
    const groups = {};
    (invoice.items || []).forEach((it) =>
        (it.taxes || []).forEach((t) => {
            const key = `${t.name} ${Number(t.rate)}%`;
            groups[key] = (groups[key] || 0) + Number(t.amount);
        }),
    );
    return Object.entries(groups).map(([label, amount]) => ({ label, amount }));
}

// The number alone: "1,200.00".
export function money(value) {
    const n = Number(value || 0);
    const decimals = currency().decimals ?? 2;
    return n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

// With the symbol: "Tk 1,200.00", "$1,200.00", "-Tk 50.00" (letter symbols get a space).
export function fmtMoney(value) {
    const n = Number(value || 0);
    const symbol = cur();
    return (n < 0 ? '-' : '') + symbol + (/\p{L}$/u.test(symbol) ? ' ' : '') + money(Math.abs(n));
}

export function fmtDate(value) {
    if (!value) return '';
    const d = new Date(String(value).length === 10 ? value + 'T00:00:00' : String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

// "05 Nov 2026, 2:30 PM" for server datetimes ("Y-m-d H:i:s", app timezone).
export function fmtDateTime(value) {
    if (!value) return '';
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return `${fmtDate(value)}, ${d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
}

// The company's timezone, shared by HandleInertiaRequests. Server times are wall-clock times in it.
export function timezone() {
    return usePage().props?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
}

// The company's current wall-clock time, "2026-09-27 19:30:00", whatever the browser's timezone.
export function nowString() {
    try {
        return new Intl.DateTimeFormat('sv-SE', {
            timeZone: timezone(), year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        }).format(new Date());
    } catch {
        const d = new Date();
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
    }
}

// The company's date today, "2026-09-27".
export function today() {
    return nowString().slice(0, 10);
}

export function monthStart() {
    return today().slice(0, 8) + '01';
}

export const STATUS_CLASSES = {
    // connection
    pending: 'bg-slate-100 text-slate-700 border-slate-300',
    active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    suspended: 'bg-amber-50 text-amber-700 border-amber-200',
    inactive: 'bg-slate-100 text-slate-600 border-slate-300',
    terminated: 'bg-red-50 text-red-700 border-red-200',
    // invoice
    draft: 'bg-slate-100 text-slate-600 border-slate-300',
    issued: 'bg-sky-50 text-sky-700 border-sky-200',
    partially_paid: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    paid: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    overdue: 'bg-red-50 text-red-700 border-red-200',
    void: 'bg-slate-100 text-slate-500 border-slate-300 line-through',
    cancelled: 'bg-slate-100 text-slate-500 border-slate-300 line-through',
    // payment
    completed: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    reversed: 'bg-red-50 text-red-700 border-red-200',
    refunded: 'bg-amber-50 text-amber-700 border-amber-200',
    partially_refunded: 'bg-amber-50 text-amber-700 border-amber-200',
    failed: 'bg-red-50 text-red-700 border-red-200',
    // online payment
    initiated: 'bg-slate-100 text-slate-600 border-slate-300',
    pending_review: 'bg-amber-50 text-amber-700 border-amber-200',
    rejected: 'bg-red-50 text-red-700 border-red-200',
    // support ticket
    open: 'bg-sky-50 text-sky-700 border-sky-200',
    in_progress: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    waiting: 'bg-amber-50 text-amber-700 border-amber-200',
    resolved: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    closed: 'bg-slate-100 text-slate-500 border-slate-300',
};

export const PRIORITY_CLASSES = {
    low: 'text-slate-500',
    normal: 'text-slate-700',
    high: 'text-amber-700 font-medium',
    urgent: 'text-red-600 font-semibold',
};

// Customer online payment methods: colour + initial for the badge (no brand logos).
export const GATEWAY_STYLES = {
    bkash: { label: 'bKash', initial: 'b', badge: 'bg-pink-600', ring: 'ring-pink-500', text: 'text-pink-600', soft: 'bg-pink-50' },
    nagad: { label: 'Nagad', initial: 'N', badge: 'bg-orange-500', ring: 'ring-orange-500', text: 'text-orange-600', soft: 'bg-orange-50' },
    rocket: { label: 'Rocket', initial: 'R', badge: 'bg-purple-700', ring: 'ring-purple-600', text: 'text-purple-700', soft: 'bg-purple-50' },
    sslcommerz: { label: 'SSLCommerz', initial: 'S', badge: 'bg-sky-700', ring: 'ring-sky-600', text: 'text-sky-700', soft: 'bg-sky-50' },
    stripe: { label: 'Stripe', initial: 'S', badge: 'bg-indigo-600', ring: 'ring-indigo-500', text: 'text-indigo-600', soft: 'bg-indigo-50' },
};

export const PAYMENT_METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'bkash', label: 'bKash' },
    { value: 'nagad', label: 'Nagad' },
    { value: 'rocket', label: 'Rocket' },
    { value: 'bank', label: 'Bank' },
    { value: 'card', label: 'Card' },
    { value: 'gateway', label: 'Online Gateway' },
    { value: 'other', label: 'Other' },
];

export function label(value) {
    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Shows validation (422) messages one per toast, otherwise the server message.
export function useApiError() {
    const toast = useToast();
    return function showError(err) {
        const r = err?.response?.data;
        if (err?.response?.status === 422 && r?.errors && typeof r.errors === 'object') {
            Object.values(r.errors).forEach((messages) => [].concat(messages).forEach((m) => toast.error(m)));
        } else {
            toast.error(r?.message || 'Something went wrong');
        }
    };
}

// Asks for a mandatory reason (reversal, void, suspension...). Resolves to the text, or null if cancelled.
export async function promptReason(title, { text = '', confirmButtonText = 'Confirm', placeholder = 'Reason (required)' } = {}) {
    const Swal = (await import('sweetalert2')).default;
    const result = await Swal.fire({
        title,
        text,
        input: 'text',
        inputPlaceholder: placeholder,
        showCancelButton: true,
        confirmButtonText,
        confirmButtonColor: '#036569',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        inputValidator: (value) => (!value || value.trim().length < 3 ? 'Please enter a reason (at least 3 characters)' : undefined),
    });
    return result.isConfirmed ? result.value.trim() : null;
}

// Connection expire time (paid until): red when unpaid or past, amber within 3 days.
export function expiryClass(value) {
    if (!value) return 'text-red-600';
    // both sides as the company's wall-clock time, so a browser in another timezone agrees
    const ms = new Date(String(value).replace(' ', 'T')) - new Date(nowString().replace(' ', 'T'));
    return ms <= 0 ? 'text-red-600 font-medium' : ms <= 3 * 86400000 ? 'text-amber-600' : 'text-emerald-700';
}
