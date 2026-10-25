/**
 * Cart keep-alive heartbeat.
 *
 * A cart reservation expires a fixed number of minutes after it was created.
 * Without a heartbeat that clock keeps running while the customer is still
 * working (filling the checkout form, approving at PayPal), so the cleanup
 * deletes the seats mid-payment.
 *
 * The beat is deliberately driven by real user interaction, not by a plain
 * interval: a tab left open unattended must still let its seats expire, or a
 * single visitor could block seats indefinitely. We only send a beat when the
 * customer actually did something since the last one and the tab is visible.
 *
 * Usage:
 *   <script src=".../cart-heartbeat.js" data-endpoint="<?= url('api/cart_update_timestamp.php') ?>"></script>
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var endpoint = script.dataset.endpoint;
    if (!endpoint) return;

    // Beat at most this often. Well below the reservation lifetime so a beat is
    // never the thing that arrives too late.
    var INTERVAL_MS = 2 * 60 * 1000;

    var activeSinceLastBeat = false;

    ['click', 'keydown', 'scroll', 'touchstart'].forEach(function (evt) {
        window.addEventListener(evt, function () {
            activeSinceLastBeat = true;
        }, { passive: true });
    });

    function beat() {
        if (!activeSinceLastBeat || document.visibilityState !== 'visible') {
            return;
        }
        activeSinceLastBeat = false;

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data || !data.success) return;

                // Seats taken over by another session were dropped server-side.
                // Reload so the customer sees the corrected cart instead of
                // continuing against stale prices and seat numbers.
                if (data.conflict_seats && data.conflict_seats.length > 0) {
                    window.location.reload();
                    return;
                }

                // Keep the visible countdown in sync with the refreshed clock.
                var timer = document.getElementById('countdown-timer');
                if (timer && data.expires_at) {
                    timer.dataset.expires = data.expires_at;
                }
            })
            .catch(function () {
                // A missed beat is harmless - the next one will carry the refresh.
            });
    }

    setInterval(beat, INTERVAL_MS);
})();
