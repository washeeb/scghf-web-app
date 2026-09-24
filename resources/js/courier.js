/**
 * The courier portal's one script: the phone's position on the "delivered"
 * form, when the browser gives it. Nothing else depends on it — the form
 * submits without a position, and the note under it says which happened.
 */
export function initCourier() {
    const form = document.querySelector('[data-courier-delivered]');

    if (!form || !('geolocation' in navigator)) {
        return;
    }

    const lat = form.querySelector('[data-courier-lat]');
    const lng = form.querySelector('[data-courier-lng]');
    const note = form.querySelector('[data-courier-location]');

    navigator.geolocation.getCurrentPosition(
        (position) => {
            lat.value = position.coords.latitude.toFixed(7);
            lng.value = position.coords.longitude.toFixed(7);
            if (note) {
                note.dataset.state = 'found';
            }
        },
        () => {
            if (note) {
                note.dataset.state = 'unavailable';
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    );
}
