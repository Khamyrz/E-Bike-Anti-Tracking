(function () {
    if (!window.RIDER_ID) {
        return;
    }

    function pingSession() {
        fetch('../api/rider-session-ping.php', {
            method: 'POST',
            credentials: 'same-origin'
        }).catch(function () {
            console.warn('Could not refresh rider online status');
        });
    }

    pingSession();
    setInterval(pingSession, 60000);
})();
