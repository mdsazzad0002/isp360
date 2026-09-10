import Swal from 'sweetalert2';

export async function confirmDialog({ title = 'Are you sure?', text = '', icon = 'warning', confirmButtonText = 'Yes, delete it', cancelButtonText = 'Cancel' } = {}) {
    const result = await Swal.fire({
        title,
        text,
        icon,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        confirmButtonColor: '#036569',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        focusCancel: true,
    });
    return result.isConfirmed;
}

export function errorMessageFrom(error, fallback = 'Something went wrong. Please try again.') {
    return error?.response?.data?.message || fallback;
}
