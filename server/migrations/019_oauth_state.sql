-- 019 — single-use state tokens for the Google sign-in round trip.
--
-- auth.php?action=google_start built an authorization URL with no `state`
-- parameter, and google_callback accepted any code handed to it. OAuth's state
-- parameter is not decoration: without it, an attacker can obtain an
-- authorization code against their OWN Google account and then cause a victim's
-- browser to submit it, which signs the victim into the attacker's account. From
-- there, anything the victim files, uploads or writes lands in a record the
-- attacker controls. It is login CSRF, and the fix is to make the callback
-- refuse a code that did not originate from a sign-in this browser started.
--
-- Kept as a table rather than a signed cookie because the SPA holds no session
-- before login and there is no server-side session store to put it in. Rows are
-- consumed on use and expire in ten minutes; expired rows are swept
-- opportunistically by the callback, so this never needs a cron of its own.

-- The same table also carries the second-factor ticket. Google sign-in used to
-- skip 2FA completely: an account with an authenticator enrolled could sign in
-- through Google with no code, so the second factor guarded one door and not the
-- other. It now stops at the same place a password sign-in does. Google has
-- already spent the authorization code by then, so the browser cannot simply
-- call the callback again with a code appended -- it gets a short-lived ticket
-- naming the user, and posts the authenticator code back against that.
--
-- `purpose` separates the two so a state can never be redeemed as a ticket.

CREATE TABLE IF NOT EXISTS oauth_states (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    -- SHA-256 of the state (or ticket) value. The raw value only ever exists in
    -- the URL the browser is redirected to, or in the reply to the browser that
    -- started the sign-in; a leaked database gives nobody a usable one.
    state_hash  CHAR(64)    NOT NULL,
    purpose     ENUM('state', 'twofa') NOT NULL DEFAULT 'state',
    -- Set only for a 'twofa' ticket: the account the code will be checked
    -- against. NULL for a 'state', which exists before anyone is identified.
    user_id     INT         NULL,
    used        TINYINT(1)  NOT NULL DEFAULT 0,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME    NOT NULL,
    UNIQUE KEY uq_oauth_states_hash (state_hash),
    KEY idx_oauth_states_expiry (expires_at),
    CONSTRAINT fk_oauth_states_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
