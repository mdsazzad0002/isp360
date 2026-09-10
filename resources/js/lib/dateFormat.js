export function formatDateTimeAmPm(value, withSeconds = false) {
    if (!value) return '';
    const d = new Date(value);
    if (isNaN(d.getTime())) return value;

    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();

    let hours = d.getHours();
    const minutes = String(d.getMinutes()).padStart(2, '0');
    const seconds = String(d.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    if (hours === 0) hours = 12;

    const time = withSeconds ? `${String(hours).padStart(2, '0')}:${minutes}:${seconds}` : `${String(hours).padStart(2, '0')}:${minutes}`;

    return `${day}-${month}-${year} ${time} ${ampm}`;
}
