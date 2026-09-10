// Resizes an uploaded image file to the given dimensions via canvas and
// returns { file, dataUrl } — used by entry forms with a fixed-size photo upload.
export function resizeImageFile(file, width = 150, height = 150) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = reject;
        reader.readAsDataURL(file);
        reader.onload = (ev) => {
            const img = new Image();
            img.onerror = reject;
            img.src = ev.target.result;
            img.onload = async () => {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                const dataUrl = canvas.toDataURL(file.type);
                const blob = await new Promise((rs) => canvas.toBlob(rs, 'image/jpeg', 1));
                resolve({ file: new File([blob], file.name, { type: blob.type }), dataUrl });
            };
        };
    });
}
