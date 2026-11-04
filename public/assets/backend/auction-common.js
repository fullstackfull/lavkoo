document.addEventListener("DOMContentLoaded", function () {

    function parseDate(dateString) {
        if (!dateString) return null;

        // Try normal parse
        let date = new Date(dateString);
        if (!isNaN(date)) return date;

        // Fix "YYYY-MM-DD HH:mm:ss" → ISO
        date = new Date(dateString.replace(' ', 'T'));
        if (!isNaN(date)) return date;

        // Fallback manual parse
        const parts = dateString.split(/[- :]/);
        return new Date(
            parseInt(parts[0]),
            parseInt(parts[1]) - 1,
            parseInt(parts[2]),
            parseInt(parts[3] || 0),
            parseInt(parts[4] || 0),
            parseInt(parts[5] || 0)
        );
    }

    function startCountdown(countdown) {
        const rawDate = countdown.dataset.end;
        const parsedDate = parseDate(rawDate);

        if (!parsedDate) return;

        const endTime = parsedDate.getTime();

        // status-text is optional — present on frontend cards, absent on admin views
        const statusText = countdown.closest("[data-timeend-text]")?.querySelector(".status-text")
            ?? countdown.closest(".m-thumbnail-wrap")?.querySelector(".status-text")
            ?? null;

        const defaultText = statusText?.textContent || null;
        const endText = statusText?.dataset?.timeendText || null;

        const hoursEl = countdown.querySelector(".hours");
        const minutesEl = countdown.querySelector(".minutes");
        const secondsEl = countdown.querySelector(".seconds");

        if (!hoursEl || !minutesEl || !secondsEl) return;

        let lastHours = null, lastMinutes = null, lastSeconds = null;

        function updateTimer() {
            const now = Date.now();
            const distance = endTime - now;

            if (distance <= 0) {
                hoursEl.textContent = "00";
                minutesEl.textContent = "00";
                secondsEl.textContent = "00";

                if (statusText && endText) {
                    statusText.textContent = endText;
                }

                return true;
            }

            const hours = Math.floor(distance / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            // Update only if changed - prevents blinking and data swinging
            if (hours !== lastHours) {
                hoursEl.textContent = String(hours).padStart(2, '0');
                lastHours = hours;
            }

            if (minutes !== lastMinutes) {
                minutesEl.textContent = String(minutes).padStart(2, '0');
                lastMinutes = minutes;
            }

            if (seconds !== lastSeconds) {
                secondsEl.textContent = String(seconds).padStart(2, '0');
                lastSeconds = seconds;
            }

            if (statusText && defaultText) {
                statusText.textContent = defaultText;
            }

            return false;
        }

        const finished = updateTimer();

        if (finished) return;

        const interval = setInterval(() => {
            const done = updateTimer();
            if (done) {
                clearInterval(interval);
            }
        }, 1000);
    }

    function initCountdown() {
        document.querySelectorAll(".countdown").forEach(startCountdown);
    }

    initCountdown();
});
