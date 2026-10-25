/**
 * Zoom control for seat plans using libs/css/seatplan-fit.css.
 *
 * Adds "− 100 % +" buttons above every .seatplan-fit container. 100 % = seats fit the
 * available width; the chosen zoom is remembered per browser (localStorage).
 */
(function () {
    const STEPS = [0.6, 0.75, 1, 1.25, 1.5, 2, 2.5];
    const STORAGE_KEY = "seatplan_zoom";

    function loadZoom() {
        try {
            const value = parseFloat(localStorage.getItem(STORAGE_KEY));
            return STEPS.includes(value) ? value : 1;
        } catch (e) {
            return 1;
        }
    }

    function saveZoom(value) {
        try {
            localStorage.setItem(STORAGE_KEY, String(value));
        } catch (e) {
            // storage unavailable (private mode etc.) - zoom just isn't remembered
        }
    }

    function init(container) {
        if (container.dataset.zoomReady) return;
        container.dataset.zoomReady = "1";

        let zoom = loadZoom();

        const bar = document.createElement("div");
        bar.className = "seatplan-zoom";
        bar.innerHTML =
            '<span class="text-muted">Größe:</span>' +
            '<div class="btn-group btn-group-sm" role="group" aria-label="Sitzplan-Größe">' +
            '<button type="button" class="btn btn-outline-secondary" data-zoom="-1" title="Verkleinern"><i class="bi bi-zoom-out"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary zoom-value" data-zoom="reset" title="Auf Fensterbreite einpassen"></button>' +
            '<button type="button" class="btn btn-outline-secondary" data-zoom="1" title="Vergrößern"><i class="bi bi-zoom-in"></i></button>' +
            "</div>";
        container.parentNode.insertBefore(bar, container);

        const label = bar.querySelector(".zoom-value");
        const apply = () => {
            container.style.setProperty("--zoom", zoom);
            label.textContent = Math.round(zoom * 100) + " %";
        };

        bar.addEventListener("click", (e) => {
            const btn = e.target.closest("button[data-zoom]");
            if (!btn) return;
            const action = btn.dataset.zoom;
            if (action === "reset") {
                zoom = 1;
            } else {
                const index = STEPS.indexOf(zoom) + parseInt(action, 10);
                zoom = STEPS[Math.max(0, Math.min(STEPS.length - 1, index))];
            }
            saveZoom(zoom);
            apply();
        });

        apply();
    }

    function initAll() {
        document.querySelectorAll(".seatplan-fit").forEach(init);
    }

    window.initSeatplanZoom = initAll;
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
    } else {
        initAll();
    }
})();
