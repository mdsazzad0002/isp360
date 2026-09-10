const KEY = 'stock_check_identity';

export function getIdentity() {
    try {
        const raw = sessionStorage.getItem(KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

export function setIdentity(name, photoDataUrl) {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ name, photo: photoDataUrl }));
    } catch (e) {
        // ignore — worst case the user re-enters identity
    }
}

export function clearIdentity() {
    try {
        sessionStorage.removeItem(KEY);
    } catch (e) {
        // ignore
    }
}

export function blobToDataUrl(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

export function dataUrlToBlob(dataUrl) {
    const [meta, base64] = dataUrl.split(',');
    const mime = meta.match(/:(.*?);/)[1];
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return new Blob([bytes], { type: mime });
}
